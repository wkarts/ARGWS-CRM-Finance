<?php
declare(strict_types=1);
namespace Multiempresa\Application;

use Multiempresa\Domain\{Failure,Id};
use Multiempresa\Infrastructure\{AdvisoryLock,Files};
use Multiempresa\Perfex\Adapter;

final class CompanyManager
{
    public function __construct(private App $app) {}

    public function organizations(array $actor): array
    {
        return (int)$actor['platform_admin']
            ? $this->app->db->all('SELECT id,name,slug,active FROM pme_organizations ORDER BY name')
            : $this->app->db->all("SELECT o.id,o.name,o.slug,o.active FROM pme_organizations o JOIN pme_org_members m ON m.organization_id=o.id WHERE m.user_id=? AND m.role='admin' AND o.id=? ORDER BY o.name",[$actor['id'],$this->app->catalog->organizationFor($actor['id'])??'']);
    }

    public function grid(array $actor,array $query): array
    {
        $params=[]; $scope='1=1';
        if (!(int)$actor['platform_admin']) {
            $scope="(EXISTS (SELECT 1 FROM pme_org_members om WHERE om.organization_id=c.organization_id AND om.user_id=? AND om.role='admin') OR EXISTS (SELECT 1 FROM pme_memberships am WHERE am.company_id=c.id AND am.user_id=? AND am.active=1))";
            $scope.=' AND c.organization_id=?';$params=[$actor['id'],$actor['id'],$this->app->catalog->organizationFor($actor['id'])??''];
        }
        $summary=$this->app->db->all('SELECT c.status,COUNT(*) AS total FROM pme_companies c WHERE '.$scope.' GROUP BY c.status',$params);
        $where=$scope;
        $status=(string)($query['status']??'');
        if ($status!=='') {
            if (!in_array($status,['draft','active','suspended','provisioning','maintenance','failed','removed'],true)) {throw new Failure('filter','Filtro de situação inválido.');}
            $where.=' AND c.status=?'; $params[]=$status;
        } else {$where.=" AND c.status<>'removed'";}
        if (!empty($query['organization_id'])) {$where.=' AND c.organization_id=?';$params[]=(string)$query['organization_id'];}
        $search=trim((string)($query['search']??''));
        if ($search!=='') {
            $search=mb_substr($search,0,120);$where.=" AND (LOWER(c.name) LIKE ? ESCAPE '!' OR LOWER(c.slug) LIKE ? ESCAPE '!' OR LOWER(o.name) LIKE ? ESCAPE '!')";
            $like='%'.str_replace(['!','%','_'],['!!','!%','!_'],mb_strtolower($search)).'%';array_push($params,$like,$like,$like);
        }
        $from=' FROM pme_companies c JOIN pme_organizations o ON o.id=c.organization_id WHERE '.$where;
        $total=(int)$this->app->db->one('SELECT COUNT(*) AS total'.$from,$params)['total'];
        $size=in_array((int)($query['size']??20),[10,20,50,100],true)?(int)($query['size']??20):20;
        $pages=max(1,(int)ceil($total/$size));$page=max(1,min($pages,(int)($query['page']??1)));
        $sort=['name'=>'c.name','organization'=>'o.name','status'=>'c.status','created'=>'c.created_at'][$query['sort']??'name']??'c.name';
        $direction=($query['direction']??'asc')==='desc'?'DESC':'ASC';
        $rows=$this->app->db->all('SELECT c.id,c.name,c.slug,c.organization_id,c.mode,c.table_prefix,c.status,c.error_code,c.created_at,c.schema_version,o.name AS organization_name,o.slug AS organization_slug,o.active AS organization_active, EXISTS (SELECT 1 FROM pme_memberships am WHERE am.company_id=c.id AND am.user_id=? AND am.active=1) AS can_open, (SELECT host FROM pme_hosts ph WHERE ph.company_id=c.id AND ph.verified=1 ORDER BY host LIMIT 1) AS verified_host, (SELECT COUNT(*) FROM pme_modules m WHERE m.company_id=c.id) AS module_count, (SELECT COUNT(*) FROM pme_modules m WHERE m.company_id=c.id AND m.active=1) AS active_module_count, (SELECT id FROM pme_onboardings w WHERE w.company_id=c.id) AS onboarding_id, (SELECT status FROM pme_onboardings w WHERE w.company_id=c.id) AS onboarding_status'.$from.' ORDER BY '.$sort.' '.$direction.',c.id LIMIT '.$size.' OFFSET '.(($page-1)*$size),array_merge([$actor['id']],$params));
        $managed=array_column($this->organizations($actor),'id');
        foreach($rows as &$row) {
            $row['managed']=(int)$actor['platform_admin']===1 || in_array($row['organization_id'],$managed,true);
            $base=$this->app->catalog->url($row);
            $row['open_url']=((int)$row['can_open']&&$this->app->catalog->canEnter($actor['id'],$row['id'])) && $row['status']==='active' && (int)$row['organization_active'] ? $base.'__pme/start' : null;
            unset($row['verified_host'],$row['can_open']);
        }
        unset($row);
        return ['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages,'size'=>$size,'summary'=>array_column($summary,'total','status')];
    }

    public function managed(array $actor,string $id): array
    {
        $company=$this->app->catalog->company($id);
        $this->app->catalog->assertManager($actor,$company['organization_id']);
        return $company;
    }

    public function staffChoices(array $actor,string $id,string $search=''): array
    {
        $company=$this->managed($actor,$id);$this->assertEditable($company);
        $secret=$this->app->vault->open($company['secrets']);
        $pdo=$this->app->storage($company['mode'])->connect($secret['connection']);
        $term='%'.str_replace(['!','%','_'],['!!','!%','!_'],mb_substr(trim($search),0,120)).'%';
        $statement=$pdo->prepare('SELECT staffid,firstname,lastname,email FROM `'.Id::prefix($company['table_prefix'], $company['mode'])."staff` WHERE active=1 AND (firstname LIKE ? ESCAPE '!' OR lastname LIKE ? ESCAPE '!' OR email LIKE ? ESCAPE '!') ORDER BY firstname,lastname,staffid LIMIT 50");
        $statement->execute([$term,$term,$term]);
        $rows=[];foreach($statement->fetchAll(\PDO::FETCH_ASSOC) as $row){$user=$this->app->db->one('SELECT id FROM pme_users WHERE email=?',[strtolower($row['email'])]);if(!$user||$this->app->catalog->organizationFor($user['id'])===$company['organization_id']){$rows[]=$row;}}
        return ['rows'=>$rows,'limit'=>50];
    }

    public function release(): array
    {
        return $this->app->db->one('SELECT r.* FROM pme_releases r JOIN pme_gate g ON g.release_id=r.id WHERE g.id=1') ?? throw new Failure('release','Núcleo publicado não encontrado.');
    }

    public function modules(): array
    {
        $release=$this->release(); $result=[];
        foreach(glob($release['root'].'/modules/*',GLOB_ONLYDIR)?:[] as $path) {
            $name=basename($path);
            if (strtolower($name)==='multiempresa' || !preg_match('/\A[a-zA-Z][a-zA-Z0-9_]{0,63}\z/',$name) || !is_file($path.'/'.$name.'.php')) {continue;}
            $header=file_get_contents($path.'/'.$name.'.php',false,null,0,8192);
            $value=static function(string $key) use($header): string {
                return preg_match('/^\s*'.preg_quote($key,'/').':\s*(.+)$/mi',$header,$m)?trim(strip_tags($m[1])):'';
            };
            $minimum=$value('Requires at least');
            $current=implode('.',str_split((string)$release['version']));
            $compatible=$minimum==='' || version_compare($current,str_replace('*','0',$minimum),'>=');
            $result[]=['name'=>$name,'label'=>$value('Module Name')?:$name,'description'=>$value('Description'),'version'=>$value('Version'),'minimum'=>$minimum,'compatible'=>$compatible];
        }
        usort($result,static fn($a,$b)=>strcasecmp($a['label'],$b['label']));return $result;
    }

    public function languages(): array
    {
        $values=[];
        foreach(glob($this->release()['root'].'/application/language/*',GLOB_ONLYDIR)?:[] as $path) {
            $name=basename($path);if(preg_match('/\A[a-zA-Z0-9_-]+\z/',$name)){$values[$name]=ucwords(str_replace('_',' ',$name));}
        }
        return $values;
    }

    public function settings(array $input): array
    {
        $language=(string)($input['language']??'portuguese_br');$timezone=(string)($input['timezone']??'America/Bahia');
        if (!isset($this->languages()[$language])) {throw new Failure('language','Selecione um idioma instalado no Perfex.');}
        if (!in_array($timezone,\DateTimeZone::listIdentifiers(),true)) {throw new Failure('timezone','Selecione um fuso horário válido.');}
        return ['name'=>Catalog::name((string)($input['name']??'')),'language'=>$language,'timezone'=>$timezone];
    }

    public function detail(array $actor,string $id): array
    {
        $c=$this->managed($actor,$id);$secrets=$this->app->vault->open($c['secrets']);$connection=$secrets['connection'];
        $safe=array_intersect_key($c,array_flip(['id','name','slug','organization_id','organization_slug','organization_name','mode','table_prefix','status','error_code','schema_version','created_at']));
        $safe['connection']=array_intersect_key($connection,array_flip(['host','port','database','username']));
        $safe['language']='';$safe['timezone']='';$safe['configuration_available']=false;
        if (in_array($c['status'],['active','suspended'],true)) {
            try {
                $pdo=$this->app->storage($c['mode'])->connect($connection);
                $options=$pdo->query("SELECT name,value FROM `".$c['table_prefix']."options` WHERE name IN ('active_language','default_timezone')")->fetchAll(\PDO::FETCH_KEY_PAIR);
                $safe['language']=$options['active_language']??'';$safe['timezone']=$options['default_timezone']??'';$safe['configuration_available']=true;
            } catch(\Throwable) { $safe['configuration_error']='Não foi possível ler as configurações do banco empresarial.'; }
        }
        $safe['modules']=$this->app->db->all('SELECT name,active FROM pme_modules WHERE company_id=? ORDER BY name',[$id]);
        $safe['onboarding']=$this->app->db->one('SELECT id,status FROM pme_onboardings WHERE company_id=?',[$id]);
        if(!$safe['configuration_available']&&$safe['onboarding']){$pending=$this->app->db->one('SELECT payload FROM pme_onboardings WHERE company_id=?',[$id]);$preferences=$pending?($this->app->vault->open($pending['payload'])['settings']??[]):[];$safe['language']=$preferences['language']??'';$safe['timezone']=$preferences['timezone']??'';}
        if($safe['onboarding']){$pending=$this->app->db->one('SELECT payload FROM pme_onboardings WHERE company_id=?',[$id]);$d=$this->app->vault->open($pending['payload']);$safe['planned_modules']=$d['modules']??array_column($safe['modules'],'name');$safe['planned_activate_modules']=$d['activate_modules']??($d['modules']??array_column(array_filter($safe['modules'],static fn($m)=>(int)$m['active']===1),'name'));$safe['registration_only']=!empty($d['registration_only']);$safe['adopt']=!empty($d['adopt']);$safe['initial_administrator']=isset($d['administrator'])?array_intersect_key($d['administrator'],array_flip(['mode','name','email','grant_creator'])):null;$safe['administrator_password_set']=!empty($d['administrator']['password']);if(!empty($d['profile'])){$safe['pending_profile']=$d['profile'];}}
        $safe['address']=$this->app->catalog->url($c);
        $safe['profile']=(new CompanyProfile($this->app))->get($id);
        $safe['certificate']=(new Certificates($this->app))->metadata($id);
        $safe['resources']=(new Resources($this->app))->publicList($c['organization_id'],$id);
        return $safe;
    }

    public function assertEditable(array $company): void
    {
        $workflow=$this->app->db->one('SELECT status FROM pme_onboardings WHERE company_id=?',[$company['id']]);
        if (!in_array($company['status'],['active','suspended'],true) || ($workflow && $workflow['status']!=='done')) {
            throw new Failure('company_state','Conclua o provisionamento ou resolva a manutenção antes de editar a empresa.',409);
        }
        if (($this->app->db->one('SELECT state FROM pme_gate WHERE id=1')['state']??'')!=='active') {throw new Failure('maintenance','Existe manutenção global em andamento.',409);}
    }

    public function writeSettings(array $company,array $settings): void
    {
        $pdo=$this->app->storage($company['mode'])->connect($this->app->vault->open($company['secrets'])['connection']);
        $pdo->beginTransaction();
        try {
            $statement=$pdo->prepare('UPDATE `'.$company['table_prefix'].'options` SET value=? WHERE name=?');
            foreach(['companyname'=>$settings['name'],'active_language'=>$settings['language'],'default_timezone'=>$settings['timezone']] as $name=>$value) {$statement->execute([$value,$name]);}
            $profile=(new CompanyProfile($this->app))->get($company['id']);
            $native=['invoice_company_name'=>$profile['legal_name']??$settings['name']];
            foreach(['cnpj'=>'company_vat','city'=>'invoice_company_city','state'=>'company_state','cep'=>'invoice_company_postal_code','phone'=>'invoice_company_phonenumber'] as $key=>$option){if(array_key_exists($key,$profile)){$native[$option]=$profile[$key];}}
            if(array_key_exists('street',$profile)){$native['invoice_company_address']=implode(', ',array_filter([$profile['street'],$profile['number']??'',$profile['complement']??'',$profile['district']??''],static fn($v)=>$v!==''));}
            if(preg_match('/\A[A-Za-z]{2}\z/',$profile['country']??'')){$native['invoice_company_country_code']=strtoupper($profile['country']);}
            foreach($native as $name=>$value){$statement->execute([$value,$name]);}
            $pdo->commit();
        } catch(\Throwable $e) {if($pdo->inTransaction()){$pdo->rollBack();}throw $e;}
    }

    public function save(array $actor,string $id,array $input): void
    {
        $initial=$this->managed($actor,$id);$orgLock=new AdvisoryLock($this->app,'organization:'.$initial['organization_id']);
        $update=new AdvisoryLock($this->app,'updates');$lock=new AdvisoryLock($this->app,'company:'.$id);
        $c=$this->managed($actor,$id);$settings=$this->settings($input);
        if(!in_array($c['status'],['draft','failed'],true)){$this->assertEditable($c);}
        if(isset($input['profile'])){(new CompanyProfile($this->app))->save($actor,$id,$input['profile']);}
        if(in_array($c['status'],['draft','failed'],true)){
            $this->app->db->exec('UPDATE pme_companies SET name=? WHERE id=?',[$settings['name'],$id]);
            $w=$this->app->db->one('SELECT id,payload,status FROM pme_onboardings WHERE company_id=?',[$id]);
            if($w){$payload=$this->app->vault->open($w['payload']);if($payload){$payload['settings']=$settings;$payload['name']=$settings['name'];$payload['profile']=(new CompanyProfile($this->app))->get($id);$this->app->db->exec('UPDATE pme_onboardings SET payload=? WHERE id=?',[$this->app->vault->seal($payload),$w['id']]);}}
            $this->app->audit->log('company.updated',$actor['id'],$id,['fields'=>['name','profile','preferences']]);return;
        }
        $this->assertEditable($c);
        $status=(string)($input['status']??$c['status']);
        if(!in_array($status,['active','suspended'],true)){throw new Failure('state','Situação inválida.');}
        // Pause access and drain native requests before writing settings in another database.
        $this->app->db->exec("UPDATE pme_companies SET status='maintenance' WHERE id=?",[$id]);
        try {
            (new Adapter($this->app))->verify($c,$this->release()['root']);
            $this->writeSettings($c,$settings);
            $this->app->db->exec('UPDATE pme_companies SET name=?,status=?,error_code=NULL WHERE id=?',[$settings['name'],$status,$id]);
            $this->app->audit->log('company.updated',$actor['id'],$id,['fields'=>['name','language','timezone','status']]);
        } catch(\Throwable $e) {
            $this->app->db->exec("UPDATE pme_companies SET status='suspended',error_code='settings_failed' WHERE id=?",[$id]);throw $e;
        }
    }

    public function remove(array $actor,string $id,string $confirmation,bool $locksHeld=false): void
    {
        $c=$this->managed($actor,$id);
        if(!hash_equals($c['name'],trim($confirmation))){throw new Failure('confirmation','Digite o nome da empresa para confirmar a remoção.');}
        $w=$this->app->db->one('SELECT id FROM pme_onboardings WHERE company_id=?',[$id]);
        $workflowLock=$w&&!$locksHeld?new AdvisoryLock($this->app,'onboarding:'.$w['id']):null;
        $update=$locksHeld?null:new AdvisoryLock($this->app,'updates');$lock=$locksHeld?null:new AdvisoryLock($this->app,'company:'.$id);
        if (($this->app->db->one('SELECT state FROM pme_gate WHERE id=1')['state']??'')!=='active') {throw new Failure('maintenance','Conclua a manutenção global antes de remover esta empresa.',409);}
        $this->app->db->transaction(function()use($actor,$id,$w){
            // The UPDATE waits for tenant requests holding shared row locks.
            $this->app->db->exec("UPDATE pme_companies SET status='removed' WHERE id=?",[$id]);
            $this->app->db->exec('UPDATE pme_memberships SET active=0 WHERE company_id=?',[$id]);
            $this->app->db->exec('DELETE FROM pme_sessions WHERE company_id=?',[$id]);
            $this->app->db->exec('DELETE FROM pme_tickets WHERE company_id=?',[$id]);
            $this->app->db->exec('DELETE FROM pme_switch_tickets WHERE source_company=? OR target_company=?',[$id,$id]);
            $this->app->db->exec('UPDATE pme_tokens SET revoked=1 WHERE company_id=?',[$id]);
            $this->app->db->exec('UPDATE pme_endpoints SET active=0 WHERE company_id=?',[$id]);
            $this->app->db->exec("UPDATE pme_events SET status='review',error_code='company_removed' WHERE company_id=? AND status IN ('pending','processing')",[$id]);
            $this->app->db->exec("UPDATE pme_jobs SET status='cancelled',finished_at=?,error_code='company_removed' WHERE target=? AND status='pending'",[time(),$id]);
            if($w){$this->app->db->exec("UPDATE pme_onboardings SET status='cancelled',payload=?,updated_at=? WHERE id=?",[$this->app->vault->seal([]),time(),$w['id']]);}
            $this->app->audit->log('company.removed',$actor['id'],$id,['data_preserved'=>true]);
        });
    }
}
