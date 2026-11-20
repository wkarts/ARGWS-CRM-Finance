<?php
declare(strict_types=1);
namespace Multiempresa\Perfex;

use Multiempresa\Application\App;
use Multiempresa\Domain\{Failure,Id};
use Multiempresa\Infrastructure\SqlScript;

/** Copies structure from the installed core. Business rows and configuration values are never read. */
final class SchemaTemplate
{
    public static function install(App $app, \PDO $target, string $prefix, array $release, ?string $company=null): void
    {
        $actual=Adapter::inspect($release['root']);
        if (!hash_equals($release['fingerprint'],$actual['fingerprint']) || (int)$actual['version']!==(int)$release['version']) {
            throw new Failure('core_updating','A aplicação mudou durante a preparação. O módulo sincronizará a versão na próxima tentativa.',503);
        }
        // Persist the exact bootstrap template before the first DDL. A later native update cannot
        // invalidate an unfinished company halfway through its tables or seed rows.
        $snapshot=$company?$app->state('companies/'.$company.'/bootstrap.pme'):null;
        if($snapshot&&is_file($snapshot)){
            $saved=$app->vault->open(file_get_contents($snapshot));
            if(($saved['company_id']??null)!==$company||($saved['prefix']??null)!==$prefix){throw new Failure('schema_owner','O modelo de implantação pertence a outro ambiente.');}
            $plan=$saved['plan'];
        }else{
            $plan=self::capture($app->db->pdo,$app->config['native_prefix'],(int)$release['version']);
            if($snapshot){\Multiempresa\Infrastructure\Files::write($snapshot,$app->vault->seal(['company_id'=>$company,'prefix'=>$prefix,'plan'=>$plan]));}
        }
        self::apply($target,$prefix,$plan,$company!==null);
        if($company && $plan['deferred_constraints']){(new \Multiempresa\Application\Resources($app))->reserve($app->catalog->company($company)['organization_id'],$company,'schema_dependencies','schema-dependencies:'.$company,['constraints'=>$plan['deferred_constraints']]);$app->audit->log('schema.dependencies_deferred',null,$company,['constraints'=>array_map(static fn($c)=>['table'=>$c['table'],'target'=>$c['target'],'constraint'=>$c['constraint']],$plan['deferred_constraints'])]);}
    }
    public static function capture(\PDO $source,string $prefix,int $version): array
    {
        Id::prefix($prefix);
        $resource=json_decode(file_get_contents(dirname(__DIR__,2).'/resources/core-defaults.json'),true,64,JSON_THROW_ON_ERROR);
        $available=[];
        foreach($source->query('SHOW FULL TABLES')->fetchAll(\PDO::FETCH_NUM) as [$table,$kind]) {$available[$table]=$kind;}
        foreach(['staff','clients','invoices','options','modules','migrations','sessions','currencies','countries','emailtemplates'] as $required) {
            if (($available[$prefix.$required]??null)!=='BASE TABLE') {throw new Failure('schema_required','Tabela nativa necessária ausente: '.$required);}
        }
        $versions=$source->query('SELECT version FROM `'.$prefix.'migrations`')->fetchAll(\PDO::FETCH_COLUMN);
        if(count($versions)!==1 || (int)$versions[0]!==$version) {
            throw new Failure('schema_source_version','O banco da instalação principal deve corresponder à versão do núcleo publicada.');
        }
        $tables=[];
        foreach($resource['tables'] as $name) {
            Id::identifier($name);
            if (!isset($available[$prefix.$name])) {continue;}
            if ($available[$prefix.$name]!=='BASE TABLE') {throw new Failure('schema_view','Views exigem um adaptador de esquema específico.');}
            $tables[$name]=$prefix.$name;
        }
        $database=$source->query('SELECT DATABASE()')->fetchColumn();
        $external=[];
        foreach($source->query('SELECT TABLE_NAME,CONSTRAINT_NAME,REFERENCED_TABLE_NAME,REFERENCED_TABLE_SCHEMA FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL')->fetchAll(\PDO::FETCH_ASSOC) as $fk) {
            if (in_array($fk['TABLE_NAME'],$tables,true) && ($fk['REFERENCED_TABLE_SCHEMA']!==$database || !in_array($fk['REFERENCED_TABLE_NAME'],$tables,true))) {
                $external[$fk['TABLE_NAME']][$fk['CONSTRAINT_NAME']]=$fk;
            }
        }
        $plan=['prefix'=>$prefix,'version'=>$version,'tables'=>[],'deferred_constraints'=>[]];
        foreach($tables as $name=>$table) {
            $ddl=$source->query('SHOW CREATE TABLE `'.$table.'`')->fetch(\PDO::FETCH_NUM)[1];
            // Custom module constraints must never retain a reference to the central database.
            foreach($external[$table]??[] as $constraint=>$fk){
                $pattern='/^\s*CONSTRAINT `'.preg_quote($constraint,'/').'`[^\n]*$/m';
                if(preg_match($pattern,$ddl,$match)){$plan['deferred_constraints'][]=['table'=>$name,'target'=>$fk['REFERENCED_TABLE_SCHEMA']===$database&&str_starts_with($fk['REFERENCED_TABLE_NAME'],$prefix)?substr($fk['REFERENCED_TABLE_NAME'],strlen($prefix)):null,'constraint'=>$constraint,'definition'=>rtrim(trim($match[0]),','),'source_prefix'=>$prefix];$ddl=preg_replace($pattern,'',$ddl);}
            }
            $ddl=preg_replace('/,\s*\n\)/',"\n)",$ddl);
            $columns=[];
            foreach($source->query('SHOW FULL COLUMNS FROM `'.$table.'`')->fetchAll(\PDO::FETCH_ASSOC) as $column) {
                // Generated columns cannot receive explicitly seeded values.
                if (!preg_match('/(?:VIRTUAL|STORED) GENERATED/i',$column['Extra'])) {$columns[]=$column['Field'];}
            }
            $defaults=$name==='migrations' ? [['version'=>$version]] : ($resource['defaults'][$name]??[]);
            if ($name==='options') {
                $names=array_flip($source->query('SELECT name FROM `'.$table.'`')->fetchAll(\PDO::FETCH_COLUMN));
                $defaults=array_values(array_filter($defaults,static fn($row)=>isset($names[$row['name']])));
            } elseif ($name==='emailtemplates') {
                $keys=[];
                foreach($source->query('SELECT slug,language FROM `'.$table.'`')->fetchAll(\PDO::FETCH_ASSOC) as $row) {$keys[$row['slug']."\0".$row['language']]=true;}
                $defaults=array_values(array_filter($defaults,static fn($row)=>isset($keys[$row['slug']."\0".$row['language']])));
            }
            $rows=[];
            foreach($defaults as $row) {
                $row=array_intersect_key($row,array_flip($columns));
                if ($row) {$rows[]=$row;}
            }
            $plan['tables'][$name]=['ddl'=>$ddl,'rows'=>$rows];
        }
        // Detect a completed concurrent native upgrade before accepting the captured structure.
        if ((int)$source->query('SELECT version FROM `'.$prefix.'migrations`')->fetchColumn()!==$version) {
            throw new Failure('schema_source_version','O núcleo foi atualizado durante a leitura do esquema. Tente novamente após a atualização.');
        }
        return $plan;
    }
    public static function apply(\PDO $target,string $prefix,array $plan,bool $resume=false): void
    {
        Id::prefix($prefix);
        foreach($target->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            if (!$resume && str_starts_with($table,$prefix)) {throw new Failure('schema_destination','O prefixo de destino deve estar vazio.');}
        }
        $foreignKeys=(int)$target->query('SELECT @@SESSION.FOREIGN_KEY_CHECKS')->fetchColumn();
        $target->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach($plan['tables'] as $name=>$table) {
                $ddl=SqlScript::rewrite($table['ddl'],$plan['prefix'],$prefix,array_keys($plan['tables']));
                // SHOW CREATE renders constraints on their own lines. Names must be unique in shared databases.
                $ddl=preg_replace_callback('/^\s*CONSTRAINT `([^`]+)`/m',static fn($m)=>'  CONSTRAINT `'.$prefix.'fk_'.substr(hash('sha256',$name.':'.$m[1]),0,24).'`',$ddl);
                // Do not inherit a production auto-increment counter, or modify quoted comments/default values.
                $ddl=preg_replace_callback('/\x27(?:\\\\.|\x27\x27|[^\x27\\\\])*\x27|"(?:\\\\.|""|[^"\\\\])*"|`(?:``|[^`])*`|\bAUTO_INCREMENT=[0-9]+/s',static fn($m)=>str_starts_with($m[0],'AUTO_INCREMENT=')?'AUTO_INCREMENT=1':$m[0],$ddl);
                if($resume){
                    $q=$target->prepare('SELECT template_hash FROM `'.$prefix.'pme_bootstrap` WHERE table_name=?');$q->execute([$name]);$hash=$q->fetchColumn();
                    if($hash && !hash_equals($hash,hash('sha256',$ddl))){throw new Failure('schema_template_changed','A estrutura de origem mudou durante a execução: '.$name);}
                    $ddl=preg_replace('/^CREATE TABLE /','CREATE TABLE IF NOT EXISTS ',$ddl);
                }
                $target->exec($ddl);
                if($resume && !$hash){$q=$target->prepare('INSERT INTO `'.$prefix.'pme_bootstrap`(table_name,template_hash) VALUES(?,?)');$q->execute([$name,hash('sha256',str_replace('CREATE TABLE IF NOT EXISTS ','CREATE TABLE ',$ddl))]);}
            }
            foreach($plan['tables'] as $name=>$table) {
                if($resume){$q=$target->prepare('SELECT seeded FROM `'.$prefix.'pme_bootstrap` WHERE table_name=?');$q->execute([$name]);if((int)$q->fetchColumn()===1){continue;}$target->beginTransaction();}
                try {
                foreach($table['rows'] as $row) {
                    $columns=array_map(static fn($column)=>'`'.str_replace('`','``',$column).'`',array_keys($row));
                    $q=$target->prepare('INSERT INTO `'.$prefix.$name.'` ('.implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')');
                    $q->execute(array_values($row));
                }
                if($resume){$q=$target->prepare('UPDATE `'.$prefix.'pme_bootstrap` SET seeded=1 WHERE table_name=?');$q->execute([$name]);$target->commit();}
                }catch(\Throwable $e){if($target->inTransaction()){$target->rollBack();}throw $e;}
            }
        } finally {$target->exec('SET FOREIGN_KEY_CHECKS='.$foreignKeys);}
    }
    public static function finalizeDependencies(App $app,array $company): array
    {
        $ledger=new \Multiempresa\Application\Resources($app);$resource=$ledger->find('schema-dependencies:'.$company['id']);if(!$resource){return [];}
        $prefix=Id::prefix($company['table_prefix'], $company['mode']);$pdo=$app->storage($company['mode'])->connect($app->vault->open($company['secrets'])['connection']);$tables=$app->storage($company['mode'])->tables($pdo,$prefix);$out=[];
        foreach($resource['data']['constraints'] as $constraint){
            if(!$constraint['target']||!in_array($prefix.$constraint['target'],$tables,true)){$out[]=['table'=>$constraint['table'],'target'=>$constraint['target'],'status'=>'not_selected'];continue;}
            $name=$prefix.'fk_'.substr(hash('sha256',$constraint['table'].':'.$constraint['constraint']),0,24);$q=$pdo->prepare('SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?');$q->execute([$prefix.$constraint['table'],$name]);
            if(!$q->fetchColumn()){$definition=SqlScript::rewrite($constraint['definition'],$constraint['source_prefix'],$prefix,[$constraint['target']]);$definition=preg_replace('/^CONSTRAINT `[^`]+`/','CONSTRAINT `'.$name.'`',$definition);$definition=preg_replace('/REFERENCES `[^`]+`\.`('.preg_quote($prefix,'/').'[A-Za-z0-9_]+)`/','REFERENCES `$1`',$definition);
                if(!preg_match('/REFERENCES `'.preg_quote($prefix.$constraint['target'],'/').'`/',$definition)){throw new Failure('schema_dependency_scope','Vínculo de tabela não pertence à empresa.');}$pdo->exec('ALTER TABLE `'.$prefix.Id::identifier($constraint['table']).'` ADD '.$definition);
            }
            $out[]=['table'=>$constraint['table'],'target'=>$constraint['target'],'status'=>'applied'];
        }
        $data=$resource['data'];$data['results']=$out;$ledger->update($resource['resource_key'],$data);$app->audit->log('schema.dependencies_verified',null,$company['id'],['constraints'=>$out]);return $out;
    }
}
