<?php
declare(strict_types=1);
namespace Multiempresa\Application;
use Multiempresa\Domain\{Failure,Id};
use Multiempresa\Infrastructure\{AdvisoryLock,Files};
use Multiempresa\Integration\Cloudflare;

/** Immutable impact snapshots; confirmation is bound to actor, target, options and current inventory. */
final class ImpactPlans
{
    public function __construct(private App $app) {}
    private function inventory(array $c,bool $data=false,bool $files=false,bool $backups=false): array
    {
        $r=['id'=>$c['id'],'name'=>$c['name'],'status'=>$c['status'],'schema_version'=>(int)$c['schema_version'],'prefix'=>$c['table_prefix'],'mode'=>$c['mode'],'resources'=>(new Resources($this->app))->publicList($c['organization_id'],$c['id']),'tables'=>[],'files'=>[],'backups'=>[],'data_requested'=>$data,'files_requested'=>$files];
        $r['certificate']=(new Certificates($this->app))->metadata($c['id']);
        $r['workflow']=$this->app->db->one('SELECT id,status,step_index,updated_at FROM pme_onboardings WHERE company_id=?',[$c['id']]);
        $connection=$this->app->vault->open($c['secrets'])['connection'];$r['database']=$connection['database']??'';
        if($r['database']===''){foreach((new Resources($this->app))->list($c['organization_id'],$c['id']) as $resource){if($resource['kind']==='database'&&$resource['status']!=='deleted'){$r['database']=$resource['data']['database'];break;}}}
        $r['configuration_fingerprint']=$this->app->vault->mac(json_encode([(new CompanyProfile($this->app))->get($c['id']),$this->app->vault->open($c['secrets']),$this->app->db->all('SELECT name,active,fingerprint FROM pme_modules WHERE company_id=? ORDER BY name',[$c['id']])],JSON_THROW_ON_ERROR));
        $deletedScope=(new Resources($this->app))->find('table-scope:'.$c['id']);
        if($data && ($deletedScope['status']??'')!=='deleted'){
            if($r['database']===''){if($c['status']!=='draft'&&$c['schema_version']>0){throw new Failure('storage_unavailable','Não foi possível identificar o banco.');}}
            else{$connection=(new DatabaseProvisioning($this->app))->administrativeConnection($c)??$connection;$pdo=$this->app->storage($c['mode'])->connect($connection);$r['tables']=$this->ownedTables($c,$pdo);}
        }
        if($files){$base=$this->app->state('companies/'.$c['id']);if(is_dir($base)){foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST) as $f){$r['files'][]=substr($f->getPathname(),strlen($base)+1).($f->isLink()?' [link]':($f->isDir()?'/':' ('.$f->getSize().')'));}sort($r['files']);}}
        if($backups){$base=$this->app->state('backups/'.$c['id']);if(is_dir($base)){foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST) as $f){$r['backups'][]=substr($f->getPathname(),strlen($base)+1).($f->isLink()?' [link]':($f->isDir()?'/':' ('.$f->getSize().')'));}sort($r['backups']);}}
        return $r;
    }
    private function ownedTables(array $c,\PDO $pdo): array
    {
        // The main installation is never a removable company scope, including through forged metadata.
        $identity=static fn($db)=>$db->query('SELECT DATABASE() AS name,@@hostname AS host,@@port AS port')->fetch(\PDO::FETCH_ASSOC);
        if($identity($pdo)===$identity($this->app->db->pdo)&&($c['mode']==='database'||str_starts_with($c['table_prefix'],$this->app->config['native_prefix'])||str_starts_with($this->app->config['native_prefix'],$c['table_prefix']))){throw new Failure('primary_protected','O banco e as tabelas da instalação principal estão protegidos.',403);}
        $driver=$this->app->storage($c['mode']);$tables=$driver->tables($pdo,$c['table_prefix']);if(!$tables){return [];}
        $database=$pdo->query('SELECT DATABASE()')->fetchColumn();$scope=(new Resources($this->app))->find('table-scope:'.$c['id']);
        $continuation=$scope&&$scope['status']==='deleting'&&($scope['data']['database']??null)===$database&&($scope['data']['prefix']??null)===$c['table_prefix']&&!array_diff($tables,$scope['data']['tables']??[]);
        if(in_array($c['table_prefix'].'pme_owner',$tables,true)){$owned=$pdo->query('SELECT company_id FROM `'.$c['table_prefix'].'pme_owner` WHERE id=1')->fetchColumn()===$c['id'];}else{$owned=$continuation;}
        if(!$owned){throw new Failure('storage_owner','Não foi comprovada a propriedade das tabelas. A exclusão de dados não pode ser autorizada.');}
        if($c['mode']==='database'&&count($pdo->query('SHOW TABLES')->fetchAll())!==count($tables)){throw new Failure('database_scope','O banco contém recursos fora desta empresa.');}
        $ownedReferences=$continuation?array_unique(array_merge($tables,$scope['data']['tables'])):$tables;
        foreach($pdo->query('SELECT TABLE_NAME,TABLE_SCHEMA,REFERENCED_TABLE_NAME,REFERENCED_TABLE_SCHEMA FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_NAME IS NOT NULL AND (TABLE_SCHEMA=DATABASE() OR REFERENCED_TABLE_SCHEMA=DATABASE())')->fetchAll() as $fk){
            $inside=$fk['TABLE_SCHEMA']===$database&&in_array($fk['TABLE_NAME'],$ownedReferences,true);$target=$fk['REFERENCED_TABLE_SCHEMA']===$database&&in_array($fk['REFERENCED_TABLE_NAME'],$ownedReferences,true);if($inside!==$target){throw new Failure('foreign_reference','Há chave estrangeira entre este recurso e outra estrutura. Resolva a dependência antes de excluir dados.');}
        }return $tables;
    }
    public function create(array $actor,string $operation,string $target,array $options=[]): array
    {
        if(!empty($options['selection_present'])){$options['modules']=(array)($options['modules']??[]);$options['activate_modules']=(array)($options['activate_modules']??[]);}
        if(!in_array($operation,['retry','reprovision','delete_company','delete_organization','dns'],true)){throw new Failure('plan_operation','Operação inválida.');}
        $organization=in_array($operation,['delete_organization','dns'],true);$manager=new CompanyManager($this->app);
        if($organization){$org=(new Organizations($this->app))->detail($actor,$target);$orgId=$target;$name=$org['name'];$companies=$this->app->db->all("SELECT * FROM pme_companies WHERE organization_id=?".($operation==='dns'?" AND status IN ('active','suspended')":'')." ORDER BY id",[$target]);}
        else{$c=$manager->managed($actor,$target);$orgId=$c['organization_id'];$name=$c['name'];$companies=[$c];}
        $data=!empty($options['delete_data']);$files=!empty($options['delete_files']);$backups=!empty($options['delete_backups']);$dns=!array_key_exists('delete_dns',$options)||!empty($options['delete_dns']);$delete=str_starts_with($operation,'delete_');
        $inventory=[];foreach($companies as $c){$inventory[]=$this->inventory($c,$delete&&$data,$delete&&$files,$delete&&$backups);}
        $global=(new GlobalSettings($this->app))->get();$orgResources=$organization?array_values(array_filter((new Resources($this->app))->publicList($orgId),static fn($r)=>$r['company_id']===null)):[];
        $impact=['operation'=>$operation,'organization_id'=>$orgId,'target_id'=>$target,'name'=>$name,'companies'=>$inventory,'organization_resources'=>$orgResources,'delete_data'=>$delete&&$data,'delete_files'=>$delete&&$files,'delete_dns'=>$delete&&$dns,'configuration_replaced'=>in_array($operation,['reprovision','retry'],true),'delete_backups'=>$delete&&$backups,'backups_preserved'=>!($delete&&$backups),'warnings'=>[]];
        $installation=(new Installation($this->app))->get();$impact['installation']=$installation['organization_id']===$orgId?$installation:null;
        if($operation==='delete_organization'&&$impact['installation']&&empty($actor['platform_admin'])){throw new Failure('primary_organization','Somente o administrador global pode excluir a organização vinculada à instalação principal.',403);}
        if($operation==='delete_company'){$impact['warnings'][]='Somente esta empresa será removida. A organização e todas as outras empresas permanecerão disponíveis, mesmo que esta seja a última empresa vinculada.';}
        if($operation==='delete_organization'&&$impact['installation']){$impact['warnings'][]='A instalação principal será desvinculada desta organização. Seu banco, arquivos, usuários, configurações e demais módulos não serão excluídos.';}
        if($delete){$impact['warnings'][]='O cadastro, seus vínculos e o histórico associado serão excluídos fisicamente. O identificador ficará disponível para um novo cadastro.';$impact['warnings'][]=$backups?'Os backups empresariais listados também serão excluídos definitivamente.':'Os backups existentes serão preservados.';}
        if($operation==='delete_organization'){$impact['warnings'][]='A organização e todas as '.count($companies).' empresas listadas serão encerradas; seus usuários perderão o acesso.';}
        if($delete){$impact['warnings'][]=$data?'As tabelas listadas e os dados nelas contidos serão excluídos de forma definitiva.':'Os bancos e tabelas serão preservados fora do cadastro. O identificador será liberado, mas dados existentes nunca serão adotados ou sobrescritos automaticamente.';$impact['warnings'][]=$files?'Os arquivos privados listados serão excluídos.':'Os arquivos serão preservados.';$impact['warnings'][]=$dns?'Somente registros DNS identificados como pertencentes ao módulo serão removidos.':'Os registros DNS serão preservados.';}
        if(in_array($operation,['retry','reprovision'],true)){$impact['warnings'][]='Banco, tabelas existentes e arquivos serão reutilizados. Não haverá limpeza da base. Cadastro e preferências serão reaplicados; módulos selecionados poderão executar instalação ou migrations e alterar suas próprias tabelas. Registros DNS administrados poderão ser criados ou atualizados.';$impact['warnings'][]='Etapas concluídas da tentativa anterior serão mantidas na retomada. Uma etapa interrompida de módulo externo poderá ser repetida após correção; revise o código de instalação e os logs.';}
        if($delete&&!empty($global['dns']['managed_wildcard_dns'])){$impact['warnings'][]='O wildcard é compartilhado pela instalação e será mantido. Somente a remoção completa do módulo pode excluir esse registro comum.';}
        $organizationService=new Organizations($this->app);$organizationData=$organizationService->detail($actor,$orgId);
        $moduleHashes=[];
        if(in_array($operation,['dns','retry','reprovision'],true)){
            $dnsConfig=$organizationService->effectiveDns($organizationData,$global['dns'],$operation==='dns');
            $impact['dns_records']=[];if(GlobalSettings::customDns($dnsConfig)){
            $impact['dns_records']=[['scope'=>'organization','name'=>$organizationService->hostname($dnsConfig,$organizationData['slug']),'type'=>$dnsConfig['type'],'target'=>$dnsConfig['target'],'action'=>$dnsConfig['enabled']?'create_or_update':'manual_routing']];
            foreach($companies as $company){if($dnsConfig['address_mode']==='company'){$impact['dns_records'][]=['scope'=>'company','company_id'=>$company['id'],'name'=>$organizationService->hostname($dnsConfig,$organizationData['slug'],$company['slug']),'type'=>$dnsConfig['type'],'target'=>$dnsConfig['target'],'action'=>$dnsConfig['enabled']?'create_or_update':'manual_routing'];}}
            }else{$impact['warnings'][]='DNS personalizado desativado: nenhum registro será criado ou alterado. Será usado o endereço atual da aplicação.';}
            if($operation==='dns'&&!GlobalSettings::customDns($dnsConfig)){throw new Failure('dns_disabled','Ative endereços personalizados nas configurações globais para aplicar DNS. O acesso pelo endereço atual continua disponível.');}
            if(!empty($dnsConfig['enabled'])&&!empty($dnsConfig['managed_wildcard_dns'])){$impact['dns_records']=[['scope'=>'installation','name'=>'*.'.$dnsConfig['base_domain'],'type'=>$dnsConfig['type'],'target'=>$dnsConfig['managed_wildcard_target']?:$dnsConfig['target'],'action'=>'create_or_update']];$impact['warnings'][]='Um único wildcard atenderá os endereços cadastrados. Hostnames desconhecidos não terão acesso à aplicação.';}
            if(!empty($dnsConfig['enabled'])&&!empty($dnsConfig['dry_run'])){$impact['warnings'][]='Simulação ativa: nenhum DNS, certificado ou endereço de acesso será alterado.';}
            if($operation==='dns'){$impact['warnings'][]='Os endereços abaixo serão aplicados. Registros anteriores serão preservados até uma exclusão explicitamente confirmada. O novo endereço exige apontamento e HTTPS no servidor.';}
        }
        if(in_array($operation,['retry','reprovision'],true)){
            $workflow=$this->app->db->one('SELECT payload,steps,step_index FROM pme_onboardings WHERE company_id=?',[$target]);$pending=$workflow?$this->app->vault->open($workflow['payload']):[];
            $names=$options['modules']??($pending['modules']??array_column($this->app->db->all('SELECT name FROM pme_modules WHERE company_id=?',[$target]),'name'));
            $names=Modules::selection($names);$activate=Modules::selection($options['activate_modules']??array_values(array_intersect($pending['activate_modules']??($pending['modules']??array_column($this->app->db->all('SELECT name FROM pme_modules WHERE company_id=? AND active=1',[$target]),'name')),$names)));if(array_diff($activate,$names)){throw new Failure('module_activation','Selecione para provisionar todos os módulos que deseja ativar.');}$available=array_column($manager->modules(),null,'name');$release=$manager->release();$impact['modules']=[];
            if(isset($options['modules'])&&$names&&empty($options['modules_reviewed'])){throw new Failure('module_review','Confirme a compatibilidade dos módulos selecionados.');}
            foreach($names as $module){if(!isset($available[$module])||strtolower($module)==='multiempresa'){throw new Failure('module_missing','Módulo indisponível na revisão.');}$moduleHashes[$module]=Files::treeHash($release['root'].'/modules/'.$module);$impact['modules'][]=['name'=>$module,'version'=>$available[$module]['version'],'action'=>in_array($module,$activate,true)?'activate_or_migrate':'available_inactive'];}
            $previous=$pending['modules']??array_column($this->app->db->all('SELECT name FROM pme_modules WHERE company_id=?',[$target]),'name');
            foreach(array_diff($previous,$names) as $removed){if(is_dir($release['root'].'/modules/'.$removed)){$moduleHashes[$removed]=Files::treeHash($release['root'].'/modules/'.$removed);}$impact['modules'][]=['name'=>$removed,'version'=>$available[$removed]['version']??'','action'=>'deactivate_or_skip'];}
            if(array_diff($previous,$names)){$impact['warnings'][]='Módulos retirados da seleção serão desativados se estiverem ativos, ou ignorados se a instalação não terminou. Tabelas e dados já criados por esses módulos serão preservados.';}
            $impact['warnings'][]='Os arquivos dos módulos serão preparados em cópias privadas desta empresa. Alterações de código desde a tentativa anterior serão revisadas novamente nas etapas correspondentes.';
            if((int)$c['schema_version']===0&&empty($pending['adopt'])&&isset($options['admin_mode'])){if(empty($options['admin_password'])&&($options['admin_email']??'')===($pending['administrator']['email']??'')){$options['admin_password']=$pending['administrator']['password']??'';}$initial=(new InitialAdministrator($this->app))->normalize($actor,$options,false,$target);$impact['warnings'][]='Administrador inicial: '.($initial['mode']==='current'?'usuário atual, com vínculo autorizado.':$initial['name'].' · '.$initial['email'].(!empty($initial['grant_creator'])?' O criador também receberá vínculo explícito.':' O criador não receberá vínculo explícito.'));}
            $impact['configuration_fields']=array_keys(array_replace($pending['settings']??['name'=>null,'language'=>null,'timezone'=>null],array_intersect_key($options,array_flip(['name','language','timezone']))));
            $impact['steps']=$workflow?json_decode($workflow['steps'],true,64,JSON_THROW_ON_ERROR):[];foreach($impact['steps'] as &$step){unset($step['fingerprint']);}unset($step);
            $impact['warnings'][]='Não há exclusão programada dos dados existentes. Migrations de módulos externos podem transformar ou remover dados dessas extensões; mantenha um backup antes de confirmar.';
        }
        $payload=['impact'=>$impact,'options'=>array_intersect_key($options,array_flip(['name','language','timezone','modules','activate_modules','modules_reviewed','profile','db_password','admin_mode','admin_name','admin_email','admin_password','grant_creator'])),'configuration_hash'=>$this->app->vault->mac(json_encode($global,JSON_THROW_ON_ERROR)),'organization_hash'=>$this->organizationHash($orgId),'module_hashes'=>$moduleHashes];
        $id=Id::new();$digest=$this->app->vault->mac(json_encode($payload,JSON_THROW_ON_ERROR));$this->app->db->exec("INSERT INTO pme_plans(id,actor_id,organization_id,company_id,operation,digest,payload,impact,status,expires_at,created_at) VALUES(?,?,?,?,?,?,?,?,'prepared',?,?)",[$id,$actor['id'],$orgId,$organization?null:$target,$operation,$digest,$this->app->vault->seal($payload),json_encode($impact,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),time()+900,time()]);
        return ['plan_id'=>$id,'impact'=>$impact,'confirmation_name'=>$name,'expires_at'=>time()+900];
    }
    public function confirm(array $actor,string $id,string $name,bool $accepted): array
    {
        $settingsLock=new AdvisoryLock($this->app,'global-settings');$planLock=new AdvisoryLock($this->app,'impact:'.$id);$r=$this->app->db->one('SELECT * FROM pme_plans WHERE id=?',[$id])??throw new Failure('plan_missing','Plano não encontrado.',404);$this->app->catalog->assertManager($actor,$r['organization_id']);
        if($r['actor_id']!==$actor['id']||$r['status']!=='prepared'||(int)$r['expires_at']<time()){throw new Failure('plan_expired','O plano expirou ou já foi utilizado. Gere uma nova revisão.',409);}
        $p=$this->app->vault->open($r['payload']);$impact=$p['impact'];if(!$accepted||!hash_equals($impact['name'],trim($name))){throw new Failure('confirmation','Marque a confirmação e digite o nome exatamente como apresentado.');}
        if(!hash_equals($r['digest'],$this->app->vault->mac(json_encode($p,JSON_THROW_ON_ERROR)))){throw new Failure('plan_integrity','Plano inválido.');}
        $orgLock=new AdvisoryLock($this->app,'organization:'.$r['organization_id']);
        if(!hash_equals($p['configuration_hash'],$this->app->vault->mac(json_encode((new GlobalSettings($this->app))->get(),JSON_THROW_ON_ERROR)))){throw new Failure('plan_changed','As configurações globais mudaram. Revise o impacto novamente.',409);}
        if(!hash_equals($p['organization_hash'],$this->organizationHash($r['organization_id']))){throw new Failure('plan_changed','A organização ou seus recursos mudaram. Gere uma nova revisão.',409);}
        foreach($p['module_hashes'] as $module=>$hash){$release=(new CompanyManager($this->app))->release();if(!hash_equals($hash,Files::treeHash($release['root'].'/modules/'.$module))){throw new Failure('plan_changed','O código dos módulos mudou após a revisão.',409);}}
        foreach($impact['companies'] as $expected){$c=$this->app->catalog->company($expected['id']);$now=$this->inventory($c,$impact['delete_data'],$impact['delete_files'],!empty($impact['delete_backups']));if($now!==$expected){throw new Failure('plan_changed','Os recursos ou o andamento da empresa mudaram. Revise o impacto novamente.',409);}}
        if(in_array($r['operation'],['delete_organization','dns'],true)){$ids=array_column($this->app->db->all("SELECT id FROM pme_companies WHERE organization_id=?".($r['operation']==='dns'?" AND status IN ('active','suspended')":'')." ORDER BY id",[$r['organization_id']]),'id');if($ids!==array_column($impact['companies'],'id')){throw new Failure('plan_changed','A organização possui novas empresas. Revise a exclusão.',409);}}
        $this->app->db->exec("UPDATE pme_plans SET status='running' WHERE id=?",[$id]);$log=new ExecutionLog($this->app);$start=microtime(true);
        $log->record('impact.confirmed','running',$actor['id'],$r['company_id'],$r['organization_id'],$id,['operation'=>$r['operation'],'impact'=>$impact]);
        try{
            if(in_array($r['operation'],['retry','reprovision'],true)){$result=(new Onboarding($this->app))->restart($actor,$r['company_id'],$r['operation'],$p['options']);}
            elseif($r['operation']==='dns'){$org=new Organizations($this->app);$result=$org->provisionDns($actor,$r['organization_id'],null,$id,null,true);foreach($this->app->db->all("SELECT id FROM pme_companies WHERE organization_id=? AND status IN ('active','suspended')",[$r['organization_id']]) as $c){$org->provisionDns($actor,$r['organization_id'],$c['id'],$id,null,true);}}
            else{
                if($r['operation']==='delete_organization'){$this->app->db->exec('UPDATE pme_organizations SET active=0 WHERE id=?',[$r['organization_id']]);}
                foreach($impact['companies'] as $c){$this->deleteCompany($actor,$c,$impact,$id);}
                if($r['operation']==='delete_organization'){
                    if($impact['delete_dns']){foreach((new Resources($this->app))->list($r['organization_id']) as $resource){if($resource['company_id']===null&&in_array($resource['kind'],['dns','custom_hostname'],true)&&$resource['status']!=='deleted'){$this->removeDns($resource,$actor['id'],$id);}}}
                    if(!empty($impact['installation'])){(new Installation($this->app))->configure($actor,'');}
                    (new CatalogRemoval($this->app))->organization($r['organization_id'],$id);
                }$result=['message'=>'Exclusão física concluída no escopo confirmado. '.($impact['delete_backups']?'Backups selecionados removidos.':'Backups preservados.')];
            }
            $this->app->db->exec("UPDATE pme_plans SET status='done',payload=? WHERE id=?",[$this->app->vault->seal([]),$id]);
            if(str_starts_with($r['operation'],'delete_')){
                (new CatalogRemoval($this->app))->history($r['company_id']?:$r['organization_id'],$r['operation']==='delete_organization',$id);
                $this->app->db->exec('DELETE FROM pme_plans WHERE id=?',[$id]);
            }else{$log->record('impact.completed','done',$actor['id'],$r['company_id'],$r['organization_id'],$id,['operation'=>$r['operation']],(int)((microtime(true)-$start)*1000));}return $result;
        }catch(\Throwable $e){if(str_starts_with($r['operation'],'delete_')){foreach($impact['companies'] as $remaining){$this->app->db->exec("UPDATE pme_companies SET status='failed',error_code='deletion_failed' WHERE id=?",[$remaining['id']]);}}if($r['operation']==='delete_organization'){$this->app->db->exec("UPDATE pme_organization_config SET status='failed' WHERE organization_id=?",[$r['organization_id']]);}$this->app->db->exec("UPDATE pme_plans SET status='failed',payload=? WHERE id=?",[$this->app->vault->seal([]),$id]);$log->record('impact.failed','failed',$actor['id'],$r['company_id'],$r['organization_id'],$id,ExecutionLog::exception($e),(int)((microtime(true)-$start)*1000));throw $e;}
    }
    private function removeDns(array $resource,string $actor,string $execution): void
    {
        $cfg=(new GlobalSettings($this->app))->get()['dns'];
        if($resource['kind']==='custom_hostname'){(new \Multiempresa\Integration\CustomHostnames($this->app))->remove($cfg,$resource);}else{(new Cloudflare($this->app))->remove($cfg,$resource,$actor,$execution);}
    }
    private function organizationHash(string $org): string
    {
        $configuration=$this->app->db->one('SELECT data,status FROM pme_organization_config WHERE organization_id=?',[$org]);
        if($configuration){$data=json_decode($configuration['data'],true);unset($data['readiness_cursor']);$configuration['data']=$data;}
        $installation=(new Installation($this->app))->get();
        return $this->app->vault->mac(json_encode([$this->app->db->one('SELECT name,slug,active FROM pme_organizations WHERE id=?',[$org]),$configuration,array_values(array_filter((new Resources($this->app))->publicList($org),static fn($r)=>$r['company_id']===null)),($installation['organization_id']===$org?$installation:null)],JSON_THROW_ON_ERROR));
    }
    private function deleteCompany(array $actor,array $inventory,array $impact,string $execution): void
    {
        $id=$inventory['id'];$workflow=$this->app->db->one('SELECT id FROM pme_onboardings WHERE company_id=?',[$id]);
        $workflowLock=$workflow?new AdvisoryLock($this->app,'onboarding:'.$workflow['id']):null;
        $update=new AdvisoryLock($this->app,'updates');$lock=new AdvisoryLock($this->app,'company:'.$id);$c=$this->app->catalog->company($id);$ledger=new Resources($this->app);
        $this->app->db->exec("UPDATE pme_companies SET status='maintenance' WHERE id=?",[$id]);
        try{if($this->inventory($c,$impact['delete_data'],$impact['delete_files'],!empty($impact['delete_backups']))!==$inventory){throw new Failure('plan_changed','Os recursos mudaram durante a confirmação. Gere uma nova revisão.');}}
        catch(\Throwable $e){$this->app->db->exec('UPDATE pme_companies SET status=? WHERE id=?',[$c['status'],$id]);throw $e;}
        (new CompanyManager($this->app))->remove($actor,$id,$inventory['name'],true);
        if($impact['delete_dns']){foreach($ledger->list($c['organization_id'],$id) as $r){if(in_array($r['kind'],['dns','custom_hostname'],true)&&$r['status']!=='deleted'){$this->removeDns($r,$actor['id'],$execution);}}}
        $this->app->db->exec('DELETE FROM pme_hosts WHERE company_id=?',[$id]);
        $scope=$ledger->find('table-scope:'.$id);
        if($impact['delete_data']&&$inventory['database']!==''&&($scope['status']??'')!=='deleted'){
            $connection=(new DatabaseProvisioning($this->app))->administrativeConnection($c)??$this->app->vault->open($c['secrets'])['connection'];$pdo=$this->app->storage($c['mode'])->connect($connection);$tables=$this->ownedTables($c,$pdo);if($tables!==$inventory['tables']){throw new Failure('deletion_changed','A lista de tabelas mudou após a confirmação.');}
            $scope=$ledger->reserve($c['organization_id'],$id,'table_scope','table-scope:'.$id,['database'=>$inventory['database'],'prefix'=>$c['table_prefix'],'tables'=>$tables]);$ledger->update($scope['resource_key'],$scope['data'],'deleting');
            // Keep referential checks enabled: an incoming FK invisible to a restricted manual user must block DROP.
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            $foreign=$pdo->query('SELECT TABLE_NAME,CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND CONSTRAINT_TYPE=\'FOREIGN KEY\'')->fetchAll();
            foreach($foreign as $fk){if(in_array($fk['TABLE_NAME'],$tables,true)){$pdo->exec('ALTER TABLE `'.Id::identifier($fk['TABLE_NAME']).'` DROP FOREIGN KEY `'.str_replace('`','``',$fk['CONSTRAINT_NAME']).'`');(new ExecutionLog($this->app))->record('sql.drop_foreign_key','done',$actor['id'],$id,$c['organization_id'],$execution,['table'=>$fk['TABLE_NAME'],'constraint'=>$fk['CONSTRAINT_NAME']]);}}
            foreach($tables as $table){$pdo->exec('DROP TABLE `'.Id::identifier($table).'`');(new ExecutionLog($this->app))->record('sql.drop_table','done',$actor['id'],$id,$c['organization_id'],$execution,['table'=>$table]);}
            $ledger->update($scope['resource_key'],$scope['data'],'deleted');
        }
        if($impact['delete_data']){
            // Table deletion is committed separately, so an interrupted DROP USER or DROP DATABASE can resume.
            $databaseService=new DatabaseProvisioning($this->app);
            foreach($ledger->list($c['organization_id'],$id) as $r){if($r['kind']==='database'&&$r['status']!=='deleted'){$databaseService->deleteDatabase($r);}}
            foreach($ledger->list($c['organization_id'],$id) as $r){if($r['kind']==='mysql_user'&&$r['status']!=='deleted'){$databaseService->deleteUser($r);}}
        }
        if($impact['delete_files']){$this->removeTree($this->app->state('companies/'.$id));$this->app->db->exec('DELETE FROM pme_certificates WHERE company_id=?',[$id]);}
        if(!empty($impact['delete_backups'])){$this->removeTree($this->app->state('backups/'.$id));}
        (new ExecutionLog($this->app))->record('company.deleted','done',$actor['id'],$id,$c['organization_id'],$execution,['tables'=>$inventory['tables'],'files_count'=>count($inventory['files']),'data_deleted'=>$impact['delete_data'],'files_deleted'=>$impact['delete_files']]);
        (new CatalogRemoval($this->app))->company($id,$execution);
    }
    private function removeTree(string $path): void
    {
        if(is_link($path)){if(!unlink($path)){throw new Failure('file_delete','Não foi possível remover um vínculo de arquivo.');}return;}
        if(is_dir($path)){foreach(new \DirectoryIterator($path) as $f){if(!$f->isDot()){$this->removeTree($f->getPathname());}}if(!rmdir($path)){throw new Failure('file_delete','Não foi possível remover um diretório.');}}
        elseif(is_file($path)&&!unlink($path)){throw new Failure('file_delete','Não foi possível remover um arquivo.');}
    }
}
