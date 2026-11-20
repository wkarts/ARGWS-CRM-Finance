<?php
declare(strict_types=1);
namespace Multiempresa\Application;
use Multiempresa\Domain\{Failure,Id};
use Multiempresa\Infrastructure\MySqlStorage;

final class DatabaseProvisioning
{
    public function __construct(private App $app) {}
    private function admin(array $cfg,?string $database=null): \PDO
    {
        $host=$cfg['host'];$port=(int)$cfg['port'];if(!preg_match('/\A[a-zA-Z0-9_.:-]+\z/',$host)||$port<1||$port>65535){throw new Failure('database_host','Conexão administrativa inválida.');}
        $dsn='mysql:host='.$host.';port='.$port.';charset=utf8mb4';if($database){$dsn.=';dbname='.Id::identifier($database);}
        return new \PDO($dsn,$cfg['username'],$cfg['password'],[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC,\PDO::ATTR_EMULATE_PREPARES=>false,\PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false,\PDO::ATTR_TIMEOUT=>10]);
    }
    public function check(array $configuration): array
    {
        $pdo=$this->admin($configuration);$grants=$pdo->query('SHOW GRANTS')->fetchAll(\PDO::FETCH_COLUMN);
        return ['connected'=>true,'server'=>(string)$pdo->getAttribute(\PDO::ATTR_SERVER_VERSION),'grants'=>array_map(static fn($g)=>preg_replace('/ IDENTIFIED .*/i','',$g),$grants)];
    }
    public function validateApplicationPermissions(\PDO $pdo,string $mode,string $database,string $prefix,array $requiredTables=[]): void
    {
        $granted=[];$required=['SELECT','INSERT','UPDATE','DELETE','CREATE','DROP','ALTER','INDEX','REFERENCES'];
        foreach($pdo->query('SHOW GRANTS')->fetchAll(\PDO::FETCH_COLUMN) as $grant){
            if(!preg_match('/^GRANT (.+?) ON (.+?) TO /i',$grant,$m)){throw new Failure('database_role','Permissões por roles exigem uma conexão de aplicação com privilégios explícitos.');}
            $privileges=strtoupper($m[1]);$scope=str_replace('`','',$m[2]);
            if($privileges==='USAGE'){continue;}
            if(str_contains(strtoupper($grant),'WITH GRANT OPTION')){throw new Failure('database_grant_option','A conexão da empresa não deve possuir GRANT OPTION.');}
            if($mode==='database' && str_replace(['\\_','\\%'],['_','%'],$scope)===$database.'.*'){
                $databasePattern=substr($scope,0,-2);
                if(preg_match('/(?<!\\\\)[_%]/',$databasePattern)){throw new Failure('database_privilege_scope','O GRANT do banco deve escapar sublinhados para impedir acesso a nomes semelhantes.');}
                $granted['*']=array_merge($granted['*']??[],str_contains($privileges,'ALL PRIVILEGES')?$required:array_map('trim',explode(',',$privileges)));continue;
            }
            if(str_starts_with($scope,$database.'.'.$prefix)&&!str_contains($scope,'*')){$table=substr($scope,strlen($database)+1);$granted[$table]=array_merge($granted[$table]??[],str_contains($privileges,'ALL PRIVILEGES')?$required:array_map('trim',explode(',',$privileges)));continue;}
            throw new Failure('database_privilege_scope','A conexão manual possui privilégios fora do escopo desta empresa. Informe uma credencial restrita ao banco exclusivo ou às tabelas do prefixo. Use o modo automático para criar a credencial.');
        }
        foreach($requiredTables as $table){if(array_diff($required,array_merge($granted['*']??[],$granted[$prefix.$table]??[]))){throw new Failure('database_permissions','A credencial não tem as permissões necessárias para criar e manter a tabela '.$prefix.$table.'. Ajuste os GRANTs antes de continuar.');}}
    }
    public function requiredTables(array $modules,string $root): array
    {
        $defaults=json_decode(file_get_contents(dirname(__DIR__,2).'/resources/core-defaults.json'),true,64,JSON_THROW_ON_ERROR);
        $available=array_flip($this->app->db->pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN));$tables=['pme_owner','pme_inbox','pme_bootstrap'];
        foreach($defaults['tables'] as $table){if(isset($available[$this->app->config['native_prefix'].$table])){$tables[]=$table;}}
        foreach($modules as $module){$tables=array_merge($tables,$this->moduleTables($root.'/modules/'.Id::identifier($module)));}
        return array_values(array_unique($tables));
    }
    public static function generatedDatabaseName(string $name): string
    {
        if(!preg_match('/\A[a-zA-Z][a-zA-Z0-9_]*\z/D',$name)){throw new Failure('invalid_identifier','Identificador SQL inválido.');}
        // mysql.db stores the escaped GRANT pattern, whose underscores also consume space.
        // Keep that pattern within 64 bytes so privileges survive a server/grant-cache reload.
        if(strlen(str_replace('_','\\_',$name))<=64){return $name;}
        $suffix='_'.substr(hash('sha256',$name),0,16);$prefix=$name;
        while(strlen(str_replace('_','\\_',$prefix.$suffix))>64){$prefix=substr($prefix,0,-1);}
        return rtrim($prefix,'_').$suffix;
    }
    public function prepare(array $p,string $company): array
    {
        if(($p['db_operation']??'manual')==='manual'){return $p;}
        $cfg=$p['global_snapshot']['database'];$org=$this->app->db->one('SELECT slug FROM pme_organizations WHERE id=?',[$p['organization_id']])??throw new Failure('organization_missing','Organização não encontrada.');
        $name=$p['mode']==='prefix'?$cfg['shared_database']:str_replace(['{org}','{company}'],[str_replace('-','_',$org['slug']),str_replace('-','_',$p['slug'])],$cfg['database_pattern']);
        $resources=new Resources($this->app);
        if($p['mode']==='database'){
            $name=self::generatedDatabaseName($name);
            // Retries keep an already reserved destination; never rename or recreate its database.
            foreach($resources->list($p['organization_id'],$company) as $resource){
                if($resource['kind']==='database'&&$resource['status']!=='deleted'){
                    if($resource['data']['host']!==$cfg['host']||(int)$resource['data']['port']!==(int)$cfg['port']){throw new Failure('admin_destination_changed','O destino reservado da empresa foi alterado.');}
                    $name=$resource['data']['database'];break;
                }
            }
        }
        Id::identifier($name);
        $pdo=$this->admin($cfg);$dbKey='db:'.hash('sha256',$cfg['host'].':'.$cfg['port'].':'.$name);
        $exists=$pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');$exists->execute([$name]);$found=(bool)$exists->fetchColumn();
        $registered=$resources->find($dbKey);
        if($p['mode']==='database'){
            if($found&&!$registered){throw new Failure('database_exists','O banco gerado já existe e não é administrado por esta empresa. Use o modo manual ou outro identificador.');}
            $r=$resources->reserve($p['organization_id'],$company,'database',$dbKey,['database'=>$name,'host'=>$cfg['host'],'port'=>(int)$cfg['port']]);
            if(!$found){$pdo->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');}
            $resources->update($dbKey,$r['data']);
        }elseif(!$found){throw new Failure('shared_database','Crie e informe o banco compartilhado nas configurações globais.');}
        $username='me_'.substr($company,0,20);$key='mysql-user:'.hash('sha256',$cfg['host'].':'.$cfg['port'].':'.$username.':'.$cfg['user_host']);
        $existingUser=$resources->find($key);$password=$existingUser['data']['password']??Id::token();
        $u=$resources->reserve($p['organization_id'],$company,'mysql_user',$key,['username'=>$username,'password'=>$password,'user_host'=>$cfg['user_host'],'database'=>$name,'host'=>$cfg['host'],'port'=>(int)$cfg['port'],'prefix'=>$p['prefix']]);
        $account=$pdo->quote($username).'@'.$pdo->quote($cfg['user_host']);
        // CREATE USER fails on an unrelated existing account; a reserved account is retried only with our generated secret.
        if($u['status']==='reserved'){
            try{$pdo->exec('CREATE USER '.$account.' IDENTIFIED BY '.$pdo->quote($password));}
            catch(\PDOException $e){if(($e->errorInfo[1]??0)!==1396){throw $e;}$probe=['host'=>$cfg['host'],'port'=>$cfg['port'],'database'=>$name,'username'=>$username,'password'=>$password];try{(new MySqlStorage())->connect($probe);}catch(\Throwable){throw new Failure('user_owner','Conta MySQL existente não corresponde à credencial gerada.');}}
        }
        if($p['mode']==='database'){$grantName=str_replace('_','\\_',$name);$pdo->exec('GRANT ALL PRIVILEGES ON `'.$grantName.'`.* TO '.$account);}
        $resources->update($key,$u['data']);
        $p['connection']=['host'=>$cfg['host'],'port'=>(int)$cfg['port'],'database'=>$name,'username'=>$cfg['username'],'password'=>$cfg['password']];
        $p['runtime_connection']=array_replace($p['connection'],['username'=>$username,'password'=>$password]);
        $p['automatic_user']=$key;
        $companyRow=$this->app->db->one('SELECT secrets FROM pme_companies WHERE id=?',[$company]);
        if($companyRow){$secret=$this->app->vault->open($companyRow['secrets']);$secret['connection']=$p['runtime_connection'];$this->app->db->exec('UPDATE pme_companies SET secrets=?,storage_identity=? WHERE id=?',[$this->app->vault->seal($secret),MySqlStorage::identity($p['runtime_connection']),$company]);}
        (new ExecutionLog($this->app))->record('database.resources','done',null,$company,$p['organization_id'],$p['execution_id']??null,['database'=>$name,'isolation'=>$p['mode'],'sql_operations'=>['CREATE DATABASE (exclusive mode)','CREATE USER','GRANT scoped privileges']]);
        return $p;
    }
    public function grantTables(string $company,array $extra=[]): void
    {
        $c=$this->app->catalog->company($company);if($c['mode']!=='prefix'){return;}$resources=new Resources($this->app);$user=null;
        foreach($resources->list($c['organization_id'],$company) as $r){if($r['kind']==='mysql_user'&&$r['status']==='ready'){$user=$r;break;}}
        if(!$user){return;}
        $cfg=(new GlobalSettings($this->app))->get()['database'];$d=$user['data'];
        if($cfg['host']!==$d['host']||(int)$cfg['port']!==(int)$d['port']){throw new Failure('admin_destination_changed','A conexão administrativa foi alterada. Restaure o destino original para gerenciar esta empresa.');}
        $pdo=$this->admin($cfg,$d['database']);$tables=$this->app->storage('prefix')->tables($pdo,$c['table_prefix']);
        foreach($extra as $suffix){Id::identifier($suffix);$tables[]=$c['table_prefix'].$suffix;}
        $account=$pdo->quote($d['username']).'@'.$pdo->quote($d['user_host']);
        foreach(array_unique($tables) as $table){if(!str_starts_with($table,$c['table_prefix'])){throw new Failure('grant_scope','Permissão fora do prefixo da empresa.');}$pdo->exec('GRANT ALL PRIVILEGES ON `'.$d['database'].'`.`'.Id::identifier($table).'` TO '.$account);}
    }
    /** Literal db_prefix() tables and an optional manifest cover generic modules without granting a shared schema. */
    public function moduleTables(string $root): array
    {
        $tables=[];$manifest=$root.'/multiempresa.json';if(is_file($manifest)){$m=json_decode(file_get_contents($manifest),true,32,JSON_THROW_ON_ERROR);$tables=$m['tables']??[];}
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS)) as $f){if($f->isFile()&&$f->getExtension()==='php'&&$f->getSize()<2097152){preg_match_all('/db_prefix\(\)\s*\.\s*[\x27"]([A-Za-z][A-Za-z0-9_]*)(?=[`\x27"\s(])/s',file_get_contents($f->getPathname()),$m);$tables=array_merge($tables,$m[1]);}}
        return array_values(array_unique(array_map(static fn($t)=>Id::identifier($t),$tables)));
    }
    public function administrativeConnection(array $company): ?array
    {
        foreach((new Resources($this->app))->list($company['organization_id'],$company['id']) as $r){if(in_array($r['kind'],['mysql_user','database'],true)&&$r['status']!=='deleted'){$cfg=(new GlobalSettings($this->app))->get()['database'];$d=$r['data'];if($cfg['host']!==$d['host']||(int)$cfg['port']!==(int)$d['port']){throw new Failure('admin_destination_changed','Destino administrativo diferente do recurso reservado.');}return ['host'=>$cfg['host'],'port'=>$cfg['port'],'database'=>$d['database'],'username'=>$cfg['username'],'password'=>$cfg['password']];}}
        return null;
    }
    public function deleteDatabase(array $resource): void
    {
        if($resource['kind']!=='database'||!$resource['company_id']){throw new Failure('database_owner','Banco sem vínculo exclusivo.');}
        $d=$resource['data'];$cfg=(new GlobalSettings($this->app))->get()['database'];
        if($cfg['host']!==$d['host']||(int)$cfg['port']!==(int)$d['port']){throw new Failure('admin_destination_changed','Destino MySQL foi alterado.');}
        $pdo=$this->admin($cfg);$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=?');$q->execute([$d['database']]);
        if((int)$q->fetchColumn()>0){throw new Failure('database_not_empty','O banco contém tabelas e não pode ser excluído neste escopo.');}
        $ledger=new Resources($this->app);$ledger->update($resource['resource_key'],$d,'deleting');
        $pdo->exec('DROP DATABASE IF EXISTS `'.Id::identifier($d['database']).'`');$ledger->update($resource['resource_key'],$d,'deleted');
    }
    public function deleteUser(array $resource): void
    {
        $d=$resource['data'];$cfg=(new GlobalSettings($this->app))->get()['database'];if($cfg['host']!==$d['host']||(int)$cfg['port']!==(int)$d['port']){throw new Failure('admin_destination_changed','Destino MySQL foi alterado.');}
        $pdo=$this->admin($cfg);if($resource['status']==='reserved'){try{$this->admin(['host'=>$d['host'],'port'=>$d['port'],'username'=>$d['username'],'password'=>$d['password']]);}catch(\Throwable){$exists=$pdo->prepare('SELECT 1 FROM mysql.user WHERE User=? AND Host=?');$exists->execute([$d['username'],$d['user_host']]);if($exists->fetchColumn()){throw new Failure('user_owner','A conta reservada não pertence ao módulo.');}}}$pdo->exec('DROP USER IF EXISTS '.$pdo->quote($d['username']).'@'.$pdo->quote($d['user_host']));(new Resources($this->app))->update($resource['resource_key'],$d,'deleted');
    }
}
