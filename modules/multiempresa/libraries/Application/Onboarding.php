<?php
 declare(strict_types=1);
 namespace Multiempresa\Application;
 use Multiempresa\Domain\{Failure,Id};
 use Multiempresa\Infrastructure\{AdvisoryLock,Files,MySqlStorage};
 use Multiempresa\Perfex\Adapter;

 /** Persistent, explicit state machine. Completion of a registration is not completion of provisioning. */
 final class Onboarding
 {
     public function __construct(private App $app) {}
     public function normalize(array $actor,array $input,?array $existing=null): array
     {
         if(!$existing&&!empty($input['company_id'])){throw new Failure('impact_required','Revise e confirme o impacto antes de reprovisionar.',409);}
         $registration=$existing&&$existing['status']==='draft'&&($this->app->db->one('SELECT status FROM pme_onboardings WHERE company_id=?',[$existing['id']])['status']??'')==='draft';
         (new CoreMaintenance($this->app))->synchronize();
         $manager=new CompanyManager($this->app);$global=(new GlobalSettings($this->app))->get();
         $org=(string)($existing['organization_id']??$input['organization_id']??'');$this->app->catalog->assertManager($actor,$org);
         if(!$this->app->db->one('SELECT id FROM pme_organizations WHERE id=? AND active=1',[$org])){throw new Failure('organization_inactive','Selecione uma organização ativa.');}
         $settings=$manager->settings(array_replace(['name'=>$existing['name']??''],$input));
         $prefix=(string)($registration?($input['prefix']??$existing['table_prefix']):($existing['table_prefix']??$input['prefix']??''));if($prefix!==''){Id::identifier($prefix);if(strlen($prefix)>16){throw new Failure('prefix_length','Use um prefixo com até 16 caracteres.');}}
         $secret=$existing?$this->app->vault->open($existing['secrets']):[];$old=$secret['connection']??[];
         $connection=['host'=>trim((string)($input['host']??$old['host']??'')),'port'=>(int)($input['port']??$old['port']??3306),'database'=>trim((string)($input['database']??$old['database']??'')),'username'=>trim((string)($input['username']??$old['username']??'')),'password'=>(string)($input['db_password']??$old['password']??'')];
         if(!empty($input['ssl_ca'])){$connection['ssl_ca']=(string)$input['ssl_ca'];}
         if($existing&&!$registration&&MySqlStorage::identity($connection)!==$existing['storage_identity']){throw new Failure('destination_change','Para mudar o destino, utilize a restauração em um destino vazio.');}
         $available=array_column($manager->modules(),null,'name');
         $defaults=array_values(array_intersect($global['modules']['defaults'],array_keys(array_filter($available,static fn($m)=>$m['compatible']))));
         $current=$existing?$this->app->db->all('SELECT name,active FROM pme_modules WHERE company_id=?',[$existing['id']]):[];
         $names=Modules::selection(!empty($input['selection_present'])?($input['modules']??[]):($input['modules']??($existing?array_column($current,'name'):$defaults)));
         $activate=Modules::selection($input['activate_modules']??(!empty($input['selection_present'])?[]:array_column(array_filter($current,static fn($m)=>(int)$m['active']===1),'name')));
         if(array_diff($activate,$names)){throw new Failure('module_activation','Selecione para provisionar todos os módulos que deseja ativar.');}
         if($names&&empty($input['modules_reviewed'])&&empty($input['save_only'])){throw new Failure('module_review','Confirme os módulos selecionados.');}
         foreach($names as $name){if(!isset($available[$name])||!$available[$name]['compatible']){throw new Failure('module_missing','Módulo indisponível ou incompatível: '.$name);}}
         $mode=(string)($registration?$global['database']['isolation']:($existing['mode']??$global['database']['isolation']));$this->app->storage($mode);$adopt=!empty($input['adopt']);
         if($mode==='database'&&!$adopt&&(!$existing||$registration)){$prefix='';}
         if($adopt&&empty($input['save_only'])&&(($mode==='prefix'&&$prefix==='')||strlen((string)($input['encryption_key']??''))!==32||(int)($input['local_staff_id']??0)<1)){throw new Failure('adopt_fields','Informe o prefixo, a chave original e o administrador local.');}
         if($registration&&empty($input['admin_password'])){$saved=$this->app->db->one('SELECT payload FROM pme_onboardings WHERE company_id=?',[$existing['id']]);$initial=$saved?($this->app->vault->open($saved['payload'])['administrator']??[]):[];if(($input['admin_mode']??'new')===($initial['mode']??'')&&($input['admin_email']??'')===($initial['email']??'')){$input['admin_password']=$initial['password']??'';}}
         $administrator=$adopt?null:(($existing&&!$registration&&(int)$existing['schema_version']>0)?null:(new InitialAdministrator($this->app))->normalize($actor,array_replace($input,['organization_id'=>$org]),!empty($input['save_only']),$existing['id']??null));
         return ['administrator'=>$administrator,'organization_id'=>$org,'name'=>$settings['name'],'slug'=>$existing['slug']??Id::slug((string)($input['slug']??'')),'mode'=>$mode,'prefix'=>$prefix,'connection'=>$connection,'settings'=>$settings,'profile'=>CompanyProfile::normalize($input['profile']??[]),'certificate_required'=>!empty($input['certificate_required']),'modules'=>$names,'activate_modules'=>$activate,'adopt'=>$adopt,'encryption_key'=>$adopt?(string)($input['encryption_key']??''):($secret['encryption_key']??''),'staff_id'=>$adopt?(int)($input['local_staff_id']??0):0,'uploads_dir'=>$adopt?(string)($input['uploads_dir']??''):'','media_dir'=>$adopt?(string)($input['media_dir']??''):'','db_operation'=>($existing&&!$registration)||$adopt?'manual':$global['database']['operation'],'global_snapshot'=>$global,'resume_authorized'=>(bool)$existing];
     }
     public function check(array $actor,array $input): array
     {
         $existing=null;if(!empty($input['company_id'])){$existing=(new CompanyManager($this->app))->managed($actor,(string)$input['company_id']);if($existing['status']!=='draft'||($this->app->db->one('SELECT status FROM pme_onboardings WHERE company_id=?',[$existing['id']])['status']??'')!=='draft'){throw new Failure('impact_required','Use a revisão de impacto para retomar esta empresa.',409);}}
         $p=$this->normalize($actor,$input,$existing);
         try{$this->preflight($p,$existing['id']??null,true);}catch(\PDOException $e){
             $code=(int)($e->errorInfo[1]??0);
             $message=match($code){1045,1698=>'Não foi possível autenticar no MySQL. Confira usuário, senha e a origem autorizada para essa credencial.',1049=>'O banco de dados informado não foi encontrado. Confira o nome do banco.',2002,2003=>'Não foi possível alcançar o servidor MySQL. Confira servidor, porta e disponibilidade.',2026=>'Não foi possível validar a conexão TLS. Confira o certificado CA e as exigências do servidor.',default=>'Não foi possível validar a conexão MySQL. Confira os dados e as permissões da credencial.'};
             throw new Failure('database_connection',$message,400);
         }
         return ['connected'=>true,'message'=>$p['db_operation']==='automatic'?'Acesso à conexão administrativa validado. O banco e a credencial da empresa serão criados durante o provisionamento.':'Conexão, permissões e disponibilidade do destino validadas.','database_operation'=>$p['db_operation'],'isolation'=>$p['mode'],'dns_enabled'=>$p['global_snapshot']['dns']['enabled']];
     }
     private function preflight(array $p,?string $exclude=null,bool $initial=false): void
     {
         if(($this->app->db->one('SELECT state FROM pme_gate WHERE id=1')['state']??'')!=='active'){throw new Failure('maintenance','O ambiente está em manutenção.',409);}
         // DNS is optional and is validated in its own stage, never in database preflight.
         if(!$exclude&&$this->app->db->one('SELECT id FROM pme_companies WHERE organization_id=? AND slug=?',[$p['organization_id'],$p['slug']])){throw new Failure('existing_registration','Já existe um cadastro com este identificador. Use Retomar ou Reprovisionar no cadastro existente.',409);}
         if($p['db_operation']==='automatic'){(new DatabaseProvisioning($this->app))->check($p['global_snapshot']['database']);return;}
         if($p['mode']==='prefix'&&$p['prefix']===''){throw new Failure('manual_prefix','No modo manual com banco compartilhado, informe o prefixo já autorizado para a credencial.');}
         $prefix=$p['prefix'];$driver=$this->app->storage($p['mode']);$driver->validate($p['connection'],$prefix);$pdo=$driver->connect($p['connection']);$tables=$driver->tables($pdo,$prefix);
         if(empty($p['automatic_user'])){$dbService=new DatabaseProvisioning($this->app);$dbService->validateApplicationPermissions($pdo,$p['mode'],$p['connection']['database'],$prefix,$dbService->requiredTables($p['modules'],(new CompanyManager($this->app))->release()['root']));}
         if((!$exclude||$initial)&&!$p['adopt']&&$tables){throw new Failure('storage_not_empty','O prefixo informado já contém tabelas. Use um destino vazio ou vincule uma instalação existente.');}
         if($p['adopt']&&!$tables){throw new Failure('adopt_empty','Não há tabelas para vincular.');}
         if($p['adopt']){
             foreach(['staff','clients','options','migrations','modules'] as $required){if(!in_array($prefix.$required,$tables,true)){throw new Failure('adopt_structure','Estrutura Perfex incompleta: '.$required);}}
             $versions=$pdo->query('SELECT version FROM `'.$prefix.'migrations`')->fetchAll(\PDO::FETCH_COLUMN);$release=(new CompanyManager($this->app))->release();
             if(count($versions)!==1||(int)$versions[0]!== (int)$release['version']){throw new Failure('schema_version','A base existente precisa corresponder ao núcleo publicado antes do vínculo.');}
         }
         foreach($pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $table){if(str_ends_with($table,'pme_owner')){$owned=substr($table,0,-9);if(str_starts_with($prefix,$owned)||str_starts_with($owned,$prefix)){if($exclude&&$owned===$prefix&&$pdo->query('SELECT company_id FROM `'.$table.'` WHERE id=1')->fetchColumn()===$exclude){continue;}throw new Failure('storage_owned','O destino pertence a outra empresa.');}}}
         foreach($this->app->db->all('SELECT id,mode,table_prefix FROM pme_companies WHERE storage_identity=?',[MySqlStorage::identity($p['connection'])]) as $other){if($other['id']===$exclude){continue;}if($p['mode']==='database'||$other['mode']==='database'||str_starts_with($prefix,$other['table_prefix'])||str_starts_with($other['table_prefix'],$prefix)){throw new Failure('storage_overlap','Banco ou prefixo reservado por outra empresa.');}}
     }
     private function steps(array $p,array $release,bool $core=true): array
     {
         $s=[];if($core){$s[]=['type'=>'database','label'=>$p['db_operation']==='automatic'?'Criar banco e credencial da aplicação':'Validar banco e permissões','status'=>'pending'];$s[]=['type'=>'core','label'=>$p['adopt']?'Vincular estrutura existente':'Criar ou retomar a estrutura de dados','status'=>'pending'];}
         $s[]=['type'=>'settings','label'=>'Aplicar cadastro e configurações','status'=>'pending'];foreach($p['modules'] as $name){$activate=in_array($name,$p['activate_modules']??$p['modules'],true);$s[]=['type'=>'module','name'=>$name,'activate'=>$activate,'label'=>($activate?'Disponibilizar e ativar: ':'Disponibilizar módulo: ').$name,'fingerprint'=>Files::treeHash($release['root'].'/modules/'.$name),'status'=>'pending'];}
         if(!empty($p['certificate_required'])){$s[]=['type'=>'certificate','label'=>'Validar certificado A1 da empresa','status'=>'pending'];}
         $s[]=['type'=>'dns','label'=>GlobalSettings::customDns($p['global_snapshot']['dns'])?'Aplicar endereços personalizados':'Usar endereço atual da aplicação','status'=>'pending'];$s[]=['type'=>'verify','label'=>'Verificar recursos e liberar acesso','status'=>'pending'];return $s;
     }
     public function begin(array $actor,array $input): array
     {
         $key=(string)($input['request_key']??'');if(!preg_match('/\A[a-f0-9]{32,64}\z/',$key)){throw new Failure('request_key','Abra novamente o assistente para iniciar uma solicitação válida.');}
         $p=$this->normalize($actor,$input);$digest=$this->app->vault->mac(json_encode($p,JSON_THROW_ON_ERROR));$lock=new AdvisoryLock($this->app,'onboarding-request:'.$key);
         $existing=$this->app->db->one('SELECT * FROM pme_onboardings WHERE request_key=?',[$key]);if($existing){if($existing['actor_id']!==$actor['id']||!hash_equals($existing['digest'],$digest)){throw new Failure('request_conflict','Solicitação já utilizada com outros dados.',409);}return $this->status($actor,$existing['id']);}
         $orgLock=new AdvisoryLock($this->app,'organization:'.$p['organization_id']);
         if(!$this->app->db->one('SELECT id FROM pme_organizations WHERE id=? AND active=1',[$p['organization_id']])){throw new Failure('organization_inactive','Organização inativa.');}
         $draft=!empty($input['save_only']);if(!$draft){$this->preflight($p);}elseif($this->app->db->one('SELECT id FROM pme_companies WHERE organization_id=? AND slug=?',[$p['organization_id'],$p['slug']])){throw new Failure('existing_registration','Este identificador já está cadastrado nesta organização.',409);}$p['registration_only']=$draft;$company=Id::new();if($p['mode']==='prefix'&&$p['prefix']===''){$p['prefix']='e'.substr($company,0,12).'_';}$release=(new CompanyManager($this->app))->release();$p['release_id']=$release['id'];$p['execution_id']=Id::new();
         $p['resume_authorized']=true;
         $this->app->db->transaction(function()use($actor,$p,$company,$key,$digest,$release,$draft){
             $secret=$this->app->vault->seal(['connection'=>$p['connection'],'encryption_key'=>$p['adopt']?$p['encryption_key']:bin2hex(random_bytes(16))]);
             $this->app->db->exec("INSERT INTO pme_companies(id,organization_id,slug,name,mode,table_prefix,storage_identity,secrets,status,created_at) VALUES(?,?,?,?,?,?,?,?,'draft',?)",[$company,$p['organization_id'],$p['slug'],$p['name'],$p['mode'],$p['prefix'],hash('sha256','reserved:'.$company),$secret,time()]);
             (new CompanyRoutes($this->app))->key($company);
             (new CompanyProfile($this->app))->save($actor,$company,$p['profile']);
             $this->insert($actor,$p,$company,$key,$digest,$draft?[]:$this->steps($p,$release));
         });return $this->status($actor,$p['execution_id']);
     }
     public function startDraft(array $actor,array $input): array
     {
         $id=(string)($input['company_id']??'');$manager=new CompanyManager($this->app);$c=$manager->managed($actor,$id);
         $orgLock=new AdvisoryLock($this->app,'organization:'.$c['organization_id']);$lock=new AdvisoryLock($this->app,'company:'.$id);
         $old=$this->app->db->one('SELECT * FROM pme_onboardings WHERE company_id=?',[$id]);
         $key=(string)($input['request_key']??'');if(!preg_match('/\A[a-f0-9]{32,64}\z/',$key)){throw new Failure('request_key','Abra novamente o assistente.');}
         $request=array_intersect_key($input,array_flip(['company_id','name','organization_id','slug','prefix','host','port','database','username','db_password','language','timezone','adopt','modules','activate_modules','selection_present','modules_reviewed','profile','certificate_required','encryption_key','local_staff_id','uploads_dir','media_dir','ssl_ca','admin_mode','admin_name','admin_email','admin_password','grant_creator']));ksort($request);if(isset($request['profile'])&&is_array($request['profile'])){ksort($request['profile']);}if(isset($request['modules'])&&is_array($request['modules'])){sort($request['modules']);}$requestDigest=$this->app->vault->mac(json_encode($request,JSON_THROW_ON_ERROR));
         if($old&&$old['status']!=='draft'&&$old['request_key']===$key&&$old['actor_id']===$actor['id']){if(!hash_equals($old['digest'],$requestDigest)){throw new Failure('request_conflict','Solicitação já utilizada com outros dados.',409);}return $this->status($actor,$old['id']);}
         if(!$old||$old['status']!=='draft'||$c['status']!=='draft'||(int)$c['schema_version']!==0){throw new Failure('impact_required','Revise e confirme a retomada ou o reprovisionamento desta empresa.',409);}
         $previous=$this->app->vault->open($old['payload']);
         $initial=$previous['administrator']??[];if(($input['admin_mode']??'new')===($initial['mode']??'')&&($input['admin_email']??'')===($initial['email']??'')&&empty($input['admin_password'])){$input['admin_password']=$initial['password']??'';}
         unset($input['save_only']);$p=$this->normalize($actor,$input,$c);$p['profile']=array_replace((new CompanyProfile($this->app))->get($id),$p['profile']);
         if($p['mode']==='prefix'&&$p['prefix']===''){$p['prefix']=$c['mode']==='prefix'&&$c['table_prefix']!==''?$c['table_prefix']:'e'.substr($id,0,12).'_';}$this->preflight($p,$id,true);$release=$manager->release();$p['release_id']=$release['id'];$p['execution_id']=Id::new();$p['resume_authorized']=true;
         $this->app->db->transaction(function()use($old,$actor,$p,$id,$key,$release,$requestDigest){
             $secrets=$this->app->vault->open($this->app->catalog->company($id)['secrets']);$secrets['connection']=$p['connection'];if($p['adopt']){$secrets['encryption_key']=$p['encryption_key'];}
             $this->app->db->exec('UPDATE pme_companies SET name=?,mode=?,table_prefix=?,secrets=? WHERE id=?',[$p['name'],$p['mode'],$p['prefix'],$this->app->vault->seal($secrets),$id]);
             (new CompanyProfile($this->app))->save($actor,$id,$p['profile']);
             $this->app->db->exec('DELETE FROM pme_onboardings WHERE id=?',[$old['id']]);
             $this->insert($actor,$p,$id,$key,$requestDigest,$this->steps($p,$release));
         });return $this->status($actor,$p['execution_id']);
     }
     private function insert(array $actor,array $p,string $company,string $key,string $digest,array $steps,int $index=0): void
     {
         $this->app->db->exec("INSERT INTO pme_onboardings(id,request_key,actor_id,organization_id,company_id,digest,payload,steps,status,step_index,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,'pending',?,?,?)",[$p['execution_id'],$key,$actor['id'],$p['organization_id'],$company,$digest,$this->app->vault->seal($p),json_encode($steps,JSON_THROW_ON_ERROR),$index,time(),time()]);
         if(!empty($p['registration_only'])){$this->app->db->exec("UPDATE pme_onboardings SET status='draft' WHERE id=?",[$p['execution_id']]);}
         (new ExecutionLog($this->app))->record(!empty($p['registration_only'])?'company.registered':'provision.requested',!empty($p['registration_only'])?'draft':'pending',$actor['id'],$company,$p['organization_id'],$p['execution_id'],['steps'=>array_column($steps,'label')]);
     }
     /** Called only after a persisted impact plan was explicitly confirmed. */
     public function restart(array $actor,string $company,string $operation,array $changes=[]): array
     {
         $manager=new CompanyManager($this->app);$c=$manager->managed($actor,$company);$old=$this->app->db->one('SELECT * FROM pme_onboardings WHERE company_id=?',[$company]);
         $lock=new AdvisoryLock($this->app,'onboarding:'.($old['id']??$company));$update=new AdvisoryLock($this->app,'updates');$companyLock=new AdvisoryLock($this->app,'company:'.$company);
         if($old&&$old['status']==='running'&&time()-(int)$old['updated_at']<5){throw new Failure('execution_running','A execução ainda está em andamento.',409);}
         if($c['status']==='removed'){throw new Failure('company_removed','Cadastre uma nova empresa; este registro está encerrado.');}
         if($old&&$old['status']==='draft'){throw new Failure('draft_configuration','Use Provisionar no cadastro para revisar o destino e os módulos.');}
         $p=$old?$this->app->vault->open($old['payload']):[];$release=$manager->release();
         if($operation==='retry'&&$p){
             $currentGlobal=(new GlobalSettings($this->app))->get();
             if(($p['db_operation']??'manual')==='automatic'){
                 // Destination naming is immutable after reservation; only credentials and service preferences refresh.
                 foreach(['host','port','shared_database','database_pattern','user_host'] as $key){$currentGlobal['database'][$key]=$p['global_snapshot']['database'][$key];}
                 $admin=(new DatabaseProvisioning($this->app))->administrativeConnection($c);
                 if($admin&&isset($p['runtime_connection'])){$p['connection']=$admin;}
             }
             $p['global_snapshot']=$currentGlobal;$p['resume_authorized']=true;
             if((int)$c['schema_version']===0&&!$p['adopt']&&isset($changes['admin_mode'])){if(empty($changes['admin_password'])&&($changes['admin_email']??'')===($p['administrator']['email']??'')){$changes['admin_password']=$p['administrator']['password']??'';}$p['administrator']=(new InitialAdministrator($this->app))->normalize($actor,array_replace($changes,['organization_id'=>$c['organization_id']]),false,$company);}
             if(isset($changes['db_password'])){$p['connection']['password']=(string)$changes['db_password'];}
             $steps=json_decode($old['steps'],true,64,JSON_THROW_ON_ERROR);$index=(int)$old['step_index'];
             foreach($steps as $i=>&$step){if($i>=$index){$step['status']='pending';if($step['type']==='module'){$step['fingerprint']=Files::treeHash($release['root'].'/modules/'.$step['name']);}}}unset($step);
             $newSettings=$manager->settings(array_replace($p['settings'],$changes));
             $newProfile=isset($changes['profile'])?CompanyProfile::normalize($changes['profile']):($p['profile']??[]);
             $settingsChanged=$newSettings!==$p['settings']||$newProfile!==($p['profile']??[]);
             $oldNames=$p['modules'];$oldActivate=$p['activate_modules']??$oldNames;$names=Modules::selection($changes['modules']??$oldNames);$activate=Modules::selection($changes['activate_modules']??array_values(array_intersect($oldActivate,$names)));if(array_diff($activate,$names)){throw new Failure('module_activation','Selecione para provisionar todos os módulos que deseja ativar.');}
             $available=array_column($manager->modules(),null,'name');
             foreach($names as $name){if(strtolower($name)==='multiempresa'||!isset($available[$name])||!$available[$name]['compatible']){throw new Failure('module_missing','Módulo indisponível ou incompatível: '.$name);}}
             // The confirmed impact contains the current package hashes, including modules removed from selection.
             // Refresh all changed approvals together so one previously completed package cannot block the next worker.
             $changed=[];
             foreach($this->app->db->all('SELECT name,fingerprint FROM pme_modules WHERE company_id=?',[$company]) as $approved){
                 $name=$approved['name'];if(!in_array($name,array_merge($oldNames,$names),true)){continue;}
                 $path=$release['root'].'/modules/'.$name;if(!is_dir($path)){continue;}$hash=Files::treeHash($path);
                 if(!hash_equals($approved['fingerprint'],$hash)){$changed[]=$name;$this->app->db->exec('UPDATE pme_modules SET fingerprint=? WHERE company_id=? AND name=?',[$hash,$company,$name]);$this->app->db->exec('DELETE FROM pme_module_reviews WHERE company_id=? AND release_id=? AND name=?',[$company,$release['id'],$name]);$this->app->audit->log('module.review_refreshed',$actor['id'],$company,['module'=>$name,'previous'=>$approved['fingerprint'],'current'=>$hash]);}
             }
             $p['name']=$newSettings['name'];$p['settings']=$newSettings;$p['profile']=$newProfile;$p['modules']=$names;$p['activate_modules']=$activate;
             if($settingsChanged||$names!==$oldNames||$activate!==$oldActivate||$changed){
                 $done=array_values(array_filter($steps,static fn($step)=>$step['status']==='done'&&(!$settingsChanged||$step['type']!=='settings')&&($step['type']!=='module'||(in_array($step['name'],$names,true)&&!in_array($step['name'],$changed,true)&&($step['activate']??true)===in_array($step['name'],$activate,true)))));
                 $pending=[];
                 foreach($this->steps($p,$release,(int)$c['schema_version']===0) as $step){
                     $reused=false;foreach($done as $finished){if($finished['type']===$step['type']&&($finished['name']??'')===($step['name']??'')){$reused=true;break;}}
                     if(!$reused){$pending[]=$step;}
                 }
                 foreach(array_diff($oldNames,$names) as $removed){
                     $active=false;
                     if((int)$c['schema_version']>0){$pdo=$this->app->storage($c['mode'])->connect($this->app->vault->open($c['secrets'])['connection']);$q=$pdo->prepare('SELECT active FROM `'.$c['table_prefix'].'modules` WHERE module_name=?');$q->execute([$removed]);$active=(int)$q->fetchColumn()===1;}
                     if($active){array_unshift($pending,['type'=>'module_disable','name'=>$removed,'label'=>'Desativar módulo: '.$removed,'status'=>'pending']);}
                     else{$this->app->db->exec('UPDATE pme_modules SET active=0 WHERE company_id=? AND name=?',[$company,$removed]);}
                 }
                 $steps=array_merge($done,$pending);$index=count($done);
             }

         }else{
             $settings=['name'=>$c['name'],'language'=>'portuguese_br','timezone'=>'America/Bahia','modules_reviewed'=>true];
             if((int)$c['schema_version']>0){$detail=$manager->detail($actor,$company);$settings['language']=$detail['language']?:'portuguese_br';$settings['timezone']=$detail['timezone']?:'America/Bahia';}
             $settings['profile']=(new CompanyProfile($this->app))->get($company);$p=$this->normalize($actor,array_replace($settings,$changes),$c);$steps=$this->steps($p,$release,(int)$c['schema_version']===0);$index=0;
         }
         $p['release_id']=$release['id'];$p['execution_id']=Id::new();$p['operation']=$operation;
         if((int)$c['schema_version']===0&&empty($p['connection'])){$p['connection']=$this->app->vault->open($c['secrets'])['connection'];}
         if((int)$c['schema_version']>0){$this->app->db->exec("UPDATE pme_companies SET status='suspended',error_code=NULL WHERE id=?",[$company]);}else{$this->app->db->exec("UPDATE pme_companies SET status='failed',error_code=NULL WHERE id=?",[$company]);}
         $this->app->db->transaction(function()use($old,$actor,$p,$company,$steps,$index){if($old){$this->app->db->exec('DELETE FROM pme_onboardings WHERE id=?',[$old['id']]);}$this->insert($actor,$p,$company,bin2hex(random_bytes(24)),$this->app->vault->mac(json_encode($p,JSON_THROW_ON_ERROR)),$steps,$index);});
         return $this->status($actor,$p['execution_id']);
     }
     private function row(array $actor,string $id): array
     {
         $row=$this->app->db->one('SELECT * FROM pme_onboardings WHERE id=?',[$id])??throw new Failure('onboarding_missing','Execução não encontrada. Consulte o histórico de logs.',404);$this->app->catalog->assertManager($actor,$row['organization_id']);return $row;
     }
     public function status(array $actor,string $id): array
     {
         $r=$this->row($actor,$id);$steps=json_decode($r['steps'],true,64,JSON_THROW_ON_ERROR);foreach($steps as &$s){unset($s['fingerprint']);}unset($s);
         $company=$this->app->db->one('SELECT id,name,slug,status,organization_id FROM pme_companies WHERE id=?',[$r['company_id']]);$url=null;
         if($company&&$company['status']==='active'&&$r['status']==='done'&&$this->app->catalog->canEnter($actor['id'],$company['id'])){$url=$this->app->catalog->url($this->app->catalog->company($company['id'])).'__pme/start';}
         $last=$this->app->db->one("SELECT details FROM pme_execution_logs WHERE execution_id=? AND status='failed' ORDER BY created_at DESC,id DESC LIMIT 1",[$id]);
         return ['id'=>$id,'company_id'=>$r['company_id'],'status'=>$r['status'],'steps'=>$steps,'step_index'=>(int)$r['step_index'],'error_code'=>$r['error_code'],'diagnosis'=>$last&&in_array($r['status'],['failed','interrupted'],true)?json_decode($last['details'],true):null,'company'=>$company,'open_url'=>$url,'completed'=>count(array_filter($steps,static fn($s)=>$s['status']==='done')),'total'=>count($steps),'can_retry'=>in_array($r['status'],['failed','interrupted'],true)];
     }
     public function advance(array $actor,string $id): array
     {
         $initial=$this->row($actor,$id);$orgLock=new AdvisoryLock($this->app,'organization:'.$initial['organization_id']);$lock=new AdvisoryLock($this->app,'onboarding:'.$id);$r=$this->row($actor,$id);
         if(in_array($r['status'],['failed','interrupted'],true)){
             $savedSteps=json_decode($r['steps'],true,64,JSON_THROW_ON_ERROR);$current=$savedSteps[(int)$r['step_index']]??[];
             $root=(new CompanyManager($this->app))->release()['root'];$path=$root.'/modules/'.($current['name']??'');
             $changed=($current['type']??'')==='module'&&is_file($path.'/'.($current['name']??'').'.php')&&!hash_equals((string)($current['fingerprint']??''),Files::treeHash($path));
             if($changed||$r['error_code']==='module_changed'){
                 $this->app->db->exec("UPDATE pme_onboardings SET status='pending',error_code=NULL WHERE id=?",[$id]);
                 $this->app->db->exec("UPDATE pme_companies SET status=CASE WHEN schema_version=0 THEN 'provisioning' ELSE 'suspended' END,error_code=NULL WHERE id=? AND status<>'removed'",[$r['company_id']]);
                 $r=$this->row($actor,$id);
             }
         }
         if(in_array($r['status'],['failed','interrupted'],true)&&in_array($r['error_code'],['core_changed','release_changed','core_updating','core_update_pending','schema_source_version'],true)){
             $this->app->db->exec("UPDATE pme_onboardings SET status='pending',error_code=NULL WHERE id=?",[$id]);
             $this->app->db->exec("UPDATE pme_companies SET status=CASE WHEN schema_version=0 THEN 'provisioning' ELSE 'suspended' END,error_code=NULL WHERE id=? AND status<>'removed'",[$r['company_id']]);
             $r=$this->row($actor,$id);
         }
         if(in_array($r['status'],['draft','done','failed','interrupted','cancelled'],true)){return $this->status($actor,$id);}
         $steps=json_decode($r['steps'],true,64,JSON_THROW_ON_ERROR);$index=(int)$r['step_index'];$started=microtime(true);
         if($r['status']==='running'){$this->fail($r,$steps,$index,new Failure('step_interrupted','A execução foi interrompida. Revise o diagnóstico e confirme a retomada.'),0,'interrupted');return $this->status($actor,$id);}
         $steps[$index]['status']='running';$this->app->db->exec("UPDATE pme_onboardings SET status='running',steps=?,updated_at=? WHERE id=?",[json_encode($steps,JSON_THROW_ON_ERROR),time(),$id]);
         try{
             $p=$this->app->vault->open($r['payload']);$manager=new CompanyManager($this->app);$company=$r['company_id'];$step=$steps[$index];
             $release=(new CoreMaintenance($this->app))->ensure($company);
             $p['release_id']=$release['id'];
             (new ModuleMaintenance($this->app))->ensure($company,true,false,true);
             if(($this->app->db->one('SELECT state FROM pme_gate WHERE id=1')['state']??'')!=='active'){throw new Failure('maintenance','Manutenção global em andamento.');}
             if($step['type']==='database'){
                 $update=new AdvisoryLock($this->app,'updates');$companyLock=new AdvisoryLock($this->app,'company:'.$company);
                 $p=(new DatabaseProvisioning($this->app))->prepare($p,$company);$this->preflight(array_replace($p,['db_operation'=>'manual']),$this->app->db->one('SELECT id FROM pme_companies WHERE id=?',[$company])?$company:null);
             }elseif($step['type']==='core'){
                 $c=$this->app->db->one('SELECT * FROM pme_companies WHERE id=?',[$company]);
                 if($c&&(int)$c['schema_version']>0){(new Adapter($this->app))->verify($c,$release['root']);$this->app->db->exec("UPDATE pme_companies SET status='suspended' WHERE id=?",[$company]);}
                 else{(new Provisioner($this->app))->create($actor,$p,$p['adopt'],$company,false);}
             }elseif($step['type']==='module'){
                 $fingerprint=Files::treeHash($release['root'].'/modules/'.$step['name']);
                 if(!hash_equals($step['fingerprint'],$fingerprint)){$steps[$index]['fingerprint']=$fingerprint;$this->app->db->exec('UPDATE pme_onboardings SET steps=? WHERE id=?',[json_encode($steps,JSON_THROW_ON_ERROR),$id]);(new ExecutionLog($this->app))->record('module.package_refreshed','done',$actor['id'],$company,$r['organization_id'],$id,['module'=>$step['name']]);}
                 if($step['activate']??true){(new Modules($this->app))->approve($company,$step['name'],true);}else{(new Modules($this->app))->makeAvailable($company,$step['name'],true);}
             }elseif($step['type']==='module_disable'){
                 (new Modules($this->app))->disable($company,$step['name']);
             }elseif($step['type']==='certificate'){
                 $meta=(new Certificates($this->app))->metadata($company);if(!$meta||$meta['valid_until']<time()){throw new Failure('certificate_required','Importe um certificado A1 válido na edição desta empresa e retome a execução.');}
             }elseif($step['type']==='dns'){
                 $result=(new Organizations($this->app))->provisionDns($actor,$p['organization_id'],$company,$id,$p['global_snapshot']['dns']);$steps[$index]['result']=$result['message']??($result['managed']?'Registros DNS aplicados.':'DNS manual: endereços registrados; confira o apontamento no servidor.');
             }else{
                 $update=new AdvisoryLock($this->app,'updates');$companyLock=new AdvisoryLock($this->app,'company:'.$company);$c=$manager->managed($actor,$company);
                 if($c['status']!=='suspended'){throw new Failure('company_state','A empresa mudou de estado durante o provisionamento.');}
                 if($step['type']==='settings'){(new CompanyProfile($this->app))->save($actor,$company,$p['profile']??[]);$manager->writeSettings($c,$p['settings']);$this->app->db->exec('UPDATE pme_companies SET name=? WHERE id=?',[$p['settings']['name'],$company]);}
                 else{
                     \Multiempresa\Perfex\SchemaTemplate::finalizeDependencies($this->app,$c);
                     (new Adapter($this->app))->verify($c,$release['root']);$pdo=$this->app->storage($c['mode'])->connect($this->app->vault->open($c['secrets'])['connection']);$pdo->exec("DELETE FROM `".$c['table_prefix']."modules` WHERE module_name='multiempresa'");
                     foreach($p['modules'] as $name){
                         $q=$pdo->prepare('SELECT active FROM `'.$c['table_prefix'].'modules` WHERE module_name=?');$q->execute([$name]);$approved=$this->app->db->one('SELECT name,fingerprint,active FROM pme_modules WHERE company_id=? AND name=?',[$company,$name]);$expected=in_array($name,$p['activate_modules']??$p['modules'],true)?1:0;
                         if((int)$q->fetchColumn()!==$expected||!$approved||(int)$approved['active']!==$expected){throw new Failure('module_missing','Módulo selecionado não está na situação solicitada: '.$name);}
                         if(!is_dir($release['root'].'/modules/'.$name)||!hash_equals($approved['fingerprint'],Files::treeHash($release['root'].'/modules/'.$name))){throw new Failure('module_changed','O módulo '.$name.' mudou após a revisão. Revise novamente antes de liberar a empresa.');}
                     }
                 }
             }
             $steps[$index]['status']='done';$steps[$index]['duration_ms']=(int)((microtime(true)-$started)*1000);$next=$index+1;$done=$next===count($steps);
             $this->app->db->transaction(function()use($done,$company,$steps,$next,$p,$id){if($done){$this->app->db->exec("UPDATE pme_companies SET status='active',error_code=NULL WHERE id=?",[$company]);}$this->app->db->exec('UPDATE pme_onboardings SET status=?,steps=?,step_index=?,payload=?,updated_at=? WHERE id=?',[$done?'done':'pending',json_encode($steps,JSON_THROW_ON_ERROR),$next,$this->app->vault->seal($done?[]:$p),time(),$id]);});
             (new ExecutionLog($this->app))->record('provision.'.$step['type'],'done',$actor['id'],$company,$r['organization_id'],$id,['step'=>$index,'module'=>$step['name']??null,'result'=>$steps[$index]['result']??null],$steps[$index]['duration_ms']);
             if($done){$this->app->audit->log('company.onboarding_completed',$actor['id'],$company,['execution_id'=>$id]);}
         }catch(\Throwable $e){$this->fail($r,$steps,$index,$e,(int)((microtime(true)-$started)*1000));}
         return $this->status($actor,$id);
     }
     private function fail(array $r,array $steps,int $index,\Throwable $e,int $duration,string $status='failed'): void
     {
         $code=$e instanceof Failure?$e->reason:'provision_failed';$steps[$index]['status']=$status;$steps[$index]['duration_ms']=$duration;
         $this->app->db->exec('UPDATE pme_onboardings SET status=?,error_code=?,steps=?,updated_at=? WHERE id=?',[$status,$code,json_encode($steps,JSON_THROW_ON_ERROR),time(),$r['id']]);
         $this->app->db->exec("UPDATE pme_companies SET status='failed',error_code=? WHERE id=? AND status<>'removed'",[$code,$r['company_id']]);
         (new ExecutionLog($this->app))->record('provision.'.$steps[$index]['type'],'failed',$r['actor_id'],$r['company_id'],$r['organization_id'],$r['id'],ExecutionLog::exception($e)+['step'=>$index,'module'=>$steps[$index]['name']??null],$duration);
     }
 }
