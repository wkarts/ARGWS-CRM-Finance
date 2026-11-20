<?php

declare(strict_types=1);

namespace Multiempresa\Application;

use Multiempresa\Infrastructure\{Files,EncryptedFile,SqlScript};
use Multiempresa\Domain\{Id,Failure};

final class Backups
{
    public function __construct(private App $app)
    {
    }
    public function create(string $company): string
    {
        $c = $this->app->catalog->company($company);
        $gate = $this->app->db->one('SELECT state FROM pme_gate WHERE id=1');
        if ($c['status'] === 'active' && $gate['state'] === 'active') {
            throw new Failure('backup_maintenance', 'Coloque a empresa em manutenção para capturar banco e anexos consistentemente.');
        }
        $s = $this->app->vault->open($c['secrets']);
        $driver = $this->app->storage($c['mode']);
        $pdo = $driver->connect($s['connection']);
        $tables = $driver->tables($pdo, $c['table_prefix']);
        if (!$tables) {
            throw new Failure('backup_empty', 'Nenhuma tabela empresarial encontrada.');
        }
        $dir = $this->app->state('backups/'.$company.'/'.date('Ymd-His').'-'.Id::new());
        Files::mkdir($dir);
        $tmp = $dir.'/database.sql';
        $handle = fopen($tmp, 'xb');
        $manifest = ['company_id' => $company,'prefix' => $c['table_prefix'],'schema_version' => $c['schema_version'],'tables' => [],'modules' => $this->app->db->all('SELECT name,fingerprint,active,approved_by FROM pme_modules WHERE company_id=?', [$company]),'created_at' => time()];
        try {
            foreach ($tables as $t) {
                $q = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
                $q->execute([$t]);
                if (strtoupper((string)$q->fetchColumn()) !== 'INNODB') {
                    throw new Failure('backup_engine', 'Backup consistente requer InnoDB.');
                }
            }
            // Views, routines and triggers require a specific adapter, never silently omitted.
            $triggers = $pdo->query('SHOW TRIGGERS')->fetchAll();
            foreach ($triggers as $t) {
                if (in_array($t['Table'], $tables, true)) {
                    throw new Failure('backup_trigger', 'Revise triggers com adaptador específico antes de capturar.');
                }
            }
            $routines = $pdo->query('SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()')->fetchAll();
            foreach ($routines as $routine) {
                if ($c['mode'] === 'database' || str_starts_with($routine['ROUTINE_NAME'], $c['table_prefix'])) {
                    throw new Failure('backup_routine', 'Rotinas armazenadas exigem adaptador específico de backup.');
                }
            }
            $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
            foreach ($pdo->query('SELECT TABLE_NAME,REFERENCED_TABLE_NAME,REFERENCED_TABLE_SCHEMA FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL') as $key) {
                if (in_array($key['TABLE_NAME'], $tables, true) && ($key['REFERENCED_TABLE_SCHEMA'] !== $database || !in_array($key['REFERENCED_TABLE_NAME'], $tables, true))) {
                    throw new Failure('backup_foreign_scope', 'Chave estrangeira aponta para fora da empresa.');
                }
            }
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            foreach ($tables as $table) {
                $create = $pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(\PDO::FETCH_NUM)[1];
                fwrite($handle, $create.";\n");
                $count = 0;
                $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                $columns=[];
                foreach($pdo->query('SHOW FULL COLUMNS FROM `'.$table.'`')->fetchAll(\PDO::FETCH_ASSOC) as $column){if(!preg_match('/(?:VIRTUAL|STORED) GENERATED/i',$column['Extra'])){$columns[]='`'.str_replace('`','``',$column['Field']).'`';}}
                $rows = $pdo->query('SELECT '.implode(',',$columns).' FROM `'.$table.'`');
                while ($row = $rows->fetch(\PDO::FETCH_ASSOC)) {
                    $names = implode(',', array_map(static fn ($n) => '`'.str_replace('`', '``', $n).'`', array_keys($row)));
                    $values = implode(',', array_map(static fn ($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), array_values($row)));
                    if (fwrite($handle, 'INSERT INTO `'.$table.'` ('.$names.') VALUES ('.$values.");\n") === false) {
                        throw new Failure('backup_disk', 'Falha ao gravar snapshot.');
                    }
                    $count++;
                }
                $rows->closeCursor();
                $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
                $manifest['tables'][$table] = $count;
            }
            $pdo->commit();
            fclose($handle);
            $handle = null;
            $zip = new \ZipArchive();
            if ($zip->open($dir.'/snapshot.zip', \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
                throw new Failure('backup_zip', 'Falha ao criar snapshot.');
            }
            $zip->addFile($tmp, 'database.sql');
            foreach (['uploads','media','modules','extensions'] as $kind) {
                $path = $this->app->state('companies/'.$company.'/'.$kind);
                if (!is_dir($path)) {
                    continue;
                }
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
                    if ($file->isLink()) {
                        throw new Failure('backup_symlink', 'Anexo com link simbólico exige revisão.');
                    }
                    if ($file->isFile()) {
                        $zip->addFile($file->getPathname(), $kind.'/'.substr($file->getPathname(), strlen($path) + 1));
                    }
                }
            }
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
            if (!$zip->close()) {
                throw new Failure('backup_zip', 'Falha ao finalizar snapshot.');
            }
            (new EncryptedFile($this->app->vault))->encrypt($dir.'/snapshot.zip', $dir.'/snapshot.pme');
            $manifest['sha256'] = hash_file('sha256', $dir.'/snapshot.pme');
            Files::write($dir.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $this->app->audit->log('backup.created', null, $company, ['sha256' => $manifest['sha256']]);
            return $dir.'/snapshot.pme';
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (is_resource($handle)) {
                fclose($handle);
            }@unlink($tmp);
            @unlink($dir.'/snapshot.zip');
        }
    }
    /** Roll back only the module-owned tenant after an automatic migration failed. */
    public function rollbackOwned(string $company,string $backup,string $checksum): void
    {
        $c=$this->app->catalog->company($company);
        if($c['status']!=='maintenance'){throw new Failure('rollback_state','A recuperação automática requer manutenção da empresa.');}
        $real=realpath($backup);$base=realpath($this->app->state('backups/'.$company));
        if(!$real||!$base||!str_starts_with($real,$base.'/')||!hash_equals($checksum,hash_file('sha256',$real))){throw new Failure('rollback_backup','O backup automático não pôde ser validado.');}
        $connection=(new DatabaseProvisioning($this->app))->administrativeConnection($c)??$this->app->vault->open($c['secrets'])['connection'];
        $pdo=$this->app->storage($c['mode'])->connect($connection);$prefix=Id::prefix($c['table_prefix'], $c['mode']);
        $identity=static fn($db)=>$db->query('SELECT DATABASE() AS name,@@hostname AS host,@@port AS port')->fetch(\PDO::FETCH_ASSOC);
        $target=$identity($pdo);
        if($target===$identity($this->app->db->pdo)&&($c['mode']==='database'||str_starts_with($prefix,$this->app->config['native_prefix'])||str_starts_with($this->app->config['native_prefix'],$prefix))){throw new Failure('primary_protected','A instalação principal está protegida.');}
        $tmp=$this->app->state('tmp/rollback-'.Id::new().'.zip');Files::mkdir(dirname($tmp));
        $zip=new \ZipArchive();
        try {
            (new EncryptedFile($this->app->vault))->decrypt($real,$tmp);
            if($zip->open($tmp,\ZipArchive::CHECKCONS)!==true){throw new Failure('rollback_backup','Backup automático inválido.');}
            $manifest=json_decode($zip->getFromName('manifest.json'),true,64,JSON_THROW_ON_ERROR);
            if(($manifest['company_id']??null)!==$company||($manifest['prefix']??null)!==$prefix||empty($manifest['tables'][$prefix.'pme_owner'])){throw new Failure('rollback_owner','O backup não pertence a este ambiente.');}
            foreach(array_keys($manifest['tables']) as $table){Id::identifier($table);if(!str_starts_with($table,$prefix)){throw new Failure('rollback_scope','Tabela fora da empresa no backup.');}}
            // Read every ZIP entry before any DDL, including encrypted stream authentication and ZIP CRC.
            for($i=0;$i<$zip->numFiles;$i++){
                $stat=$zip->statIndex($i);$stream=$zip->getStream($stat['name']);
                if(!$stream){throw new Failure('rollback_backup','Arquivo incompleto no backup automático.');}
                $crc=hash_init('crc32b');$bytes=hash_update_stream($crc,$stream);fclose($stream);
                if($bytes!==$stat['size']||strtolower(hash_final($crc))!==sprintf('%08x',$stat['crc'])){throw new Failure('rollback_backup','Arquivo incompleto no backup automático.');}
            }
            $zip->close();
        } finally { @unlink($tmp); }
        $key='core_rollback:'.$company;$row=$this->app->db->one('SELECT value FROM pme_settings WHERE name=?',[$key]);$saved=$row?$this->app->vault->open($row['value']):null;
        $tables=$this->app->storage($c['mode'])->tables($pdo,$prefix);
        if($saved){
            if($saved['checksum']!==$checksum||$saved['target']!==$target||$saved['prefix']!==$prefix||array_diff($tables,$saved['tables'])){throw new Failure('rollback_scope','O destino da recuperação foi alterado.');}
        } else {
            if(!in_array($prefix.'pme_owner',$tables,true)||$pdo->query('SELECT company_id FROM `'.$prefix.'pme_owner` WHERE id=1')->fetchColumn()!==$company){throw new Failure('storage_owner','Não foi possível comprovar a propriedade do ambiente.');}
            $saved=['checksum'=>$checksum,'target'=>$target,'prefix'=>$prefix,'tables'=>array_values(array_unique(array_merge($tables,array_keys($manifest['tables']))))];
        }
        foreach($pdo->query('SELECT TABLE_SCHEMA,TABLE_NAME,REFERENCED_TABLE_SCHEMA,REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_NAME IS NOT NULL AND (TABLE_SCHEMA=DATABASE() OR REFERENCED_TABLE_SCHEMA=DATABASE())')->fetchAll() as $fk){
            $inside=$fk['TABLE_SCHEMA']===$target['name']&&in_array($fk['TABLE_NAME'],$saved['tables'],true);$other=$fk['REFERENCED_TABLE_SCHEMA']===$target['name']&&in_array($fk['REFERENCED_TABLE_NAME'],$saved['tables'],true);
            if($inside!==$other){throw new Failure('rollback_scope','Uma dependência externa impede a recuperação automática.');}
        }
        $this->app->db->exec('INSERT INTO pme_settings(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',[$key,$this->app->vault->seal($saved)]);
        $foreign=(int)$pdo->query('SELECT @@SESSION.FOREIGN_KEY_CHECKS')->fetchColumn();$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try{foreach($tables as $table){$pdo->exec('DROP TABLE `'.Id::identifier($table).'`');}}finally{$pdo->exec('SET FOREIGN_KEY_CHECKS='.$foreign);}
        $files=$this->app->state('recovery/automatic-'.Id::new());
        $restored=$this->restoreEmpty($real,$connection,$prefix,$files,$company);
        foreach(['uploads','media','modules','extensions'] as $kind){
            $dest=$this->app->state('companies/'.$company.'/'.$kind);
            \Multiempresa\Perfex\ModuleRuntime::remove($dest);
            if(is_dir($files.'/'.$kind)&&!rename($files.'/'.$kind,$dest)){throw new Failure('rollback_files','A restauração automática dos arquivos está pendente.');}
        }
        \Multiempresa\Perfex\ModuleRuntime::remove($files);
        // Rebuild private native extension points from the restored module extensions.
        \Multiempresa\Perfex\ModuleRuntime::remove($this->app->state('companies/'.$company.'/releases'));
        $this->app->db->transaction(function()use($company,$restored,$key){
            $this->app->db->exec('UPDATE pme_companies SET schema_version=? WHERE id=?',[$restored['schema_version'],$company]);
            $this->app->db->exec('DELETE FROM pme_modules WHERE company_id=?',[$company]);
            foreach($restored['modules']??[] as $m){$this->app->db->exec('INSERT INTO pme_modules(company_id,name,fingerprint,active,approved_by) VALUES(?,?,?,?,?)',[$company,$m['name'],$m['fingerprint'],$m['active'],$m['approved_by']]);}
            $this->app->db->exec('DELETE FROM pme_settings WHERE name=?',[$key]);
            $this->app->audit->log('core.rollback_completed',null,$company);
        });
    }

    /** Restore only to EMPTY tables and a NEW file directory. Production is never overwritten. */
    public function restoreEmpty(string $backup, array $target, string $prefix, string $files, ?string $expectedCompany = null): array
    {
        $mode=$expectedCompany!==null?$this->app->catalog->company($expectedCompany)['mode']:($prefix===''?'database':'prefix');
        Id::prefix($prefix, $mode);
        $driver = $this->app->storage($mode);
        $pdo = $driver->connect($target);
        if ($driver->tables($pdo, $prefix) || file_exists($files)) {
            throw new Failure('restore_not_empty', 'O banco/prefixo e o diretório de destino devem estar vazios.');
        }
        $tmp = $this->app->state('tmp/restore-'.Id::new().'.zip');
        Files::mkdir(dirname($tmp));
        (new EncryptedFile($this->app->vault))->decrypt($backup, $tmp);
        $zip = new \ZipArchive();
        try {
            if ($zip->open($tmp) !== true) {
                throw new Failure('restore_zip', 'Snapshot inválido.');
            }
            $m = json_decode($zip->getFromName('manifest.json'), true, 64, JSON_THROW_ON_ERROR);
            if ($expectedCompany !== null && ($m['company_id'] ?? '') !== $expectedCompany) { throw new Failure('restore_company','Backup pertence a outra empresa.'); }
            $sql = $zip->getStream('database.sql');
            if (!is_resource($sql) || !isset($m['prefix'],$m['tables'])) {
                throw new Failure('restore_manifest', 'Manifesto inválido.');
            }
            // Validate all names before any write.
            for ($i = 0;
                $i < $zip->numFiles;
                $i++) {
                $name = $zip->getNameIndex($i);
                if (in_array($name, ['database.sql','manifest.json'], true)) {
                    continue;
                }
                if (!preg_match('~\A(?:uploads|media|modules|extensions)/[^\\\\\x00]+\z~', $name) || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $name)) {
                    throw new Failure('restore_path', 'Caminho inseguro no snapshot.');
                }
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            try {
                SqlScript::importStream($pdo, $sql, $m['prefix'], $prefix, array_keys($m['tables']));
            } finally {
                fclose($sql);
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            }
            $keys = $pdo->query("SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME,REFERENCED_TABLE_SCHEMA FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY ORDINAL_POSITION")->fetchAll();
            $groups = [];
            foreach ($keys as $key) {
                if (str_starts_with($key['TABLE_NAME'], $prefix)) {
                    $groups[$key['TABLE_NAME'].':'.$key['CONSTRAINT_NAME']][] = $key;
                }
            }
            $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
            foreach ($groups as $columns) {
                $key = $columns[0];
                if ($key['REFERENCED_TABLE_SCHEMA'] !== $database || !str_starts_with($key['REFERENCED_TABLE_NAME'], $prefix)) {
                    throw new Failure('restore_foreign_scope', 'Chave estrangeira aponta para fora da empresa.');
                }
                $joins = [];
                $nonnull = [];
                foreach ($columns as $column) {
                    $joins[] = 'c.`'.$column['COLUMN_NAME'].'`=p.`'.$column['REFERENCED_COLUMN_NAME'].'`';
                    $nonnull[] = 'c.`'.$column['COLUMN_NAME'].'` IS NOT NULL';
                }
                $check = 'SELECT COUNT(*) FROM `'.$key['TABLE_NAME'].'` c LEFT JOIN `'.$key['REFERENCED_TABLE_NAME'].'` p ON '.implode(' AND ', $joins).' WHERE '.implode(' AND ', $nonnull).' AND p.`'.$key['REFERENCED_COLUMN_NAME'].'` IS NULL';
                if ((int)$pdo->query($check)->fetchColumn() !== 0) {
                    throw new Failure('restore_foreign_key', 'Integridade referencial não confirmada na restauração.');
                }
            }
            foreach ($m['tables'] as $table => $count) {
                $dest = $prefix.substr($table, strlen($m['prefix']));
                if ((int)$pdo->query('SELECT COUNT(*) FROM `'.$dest.'`')->fetchColumn() !== (int)$count) {
                    throw new Failure('restore_count', 'Divergência na contagem restaurada.');
                }
            }
            Files::mkdir($files);
            for ($i = 0;
                $i < $zip->numFiles;
                $i++) {
                $name = $zip->getNameIndex($i);
                if (!str_starts_with($name, 'uploads/') && !str_starts_with($name, 'media/') && !str_starts_with($name, 'modules/') && !str_starts_with($name, 'extensions/')) {
                    continue;
                }
                Files::mkdir(dirname($files.'/'.$name));
                $source = $zip->getStream($name);
                $destination = fopen($files.'/'.$name, 'xb');
                if (!$source || !$destination) {
                    throw new Failure('restore_file', 'Falha ao abrir anexo para restauração.');
                }
                try {
                    $bytes = stream_copy_to_stream($source, $destination);
                    $stat = $zip->statName($name);
                    if ($bytes === false || $bytes !== $stat['size']) {
                        throw new Failure('restore_file', 'Anexo restaurado incompleto.');
                    }
                } finally {
                    fclose($source);
                    fclose($destination);
                }
                chmod($files.'/'.$name, 0600);
            }
            $this->app->audit->log('backup.restore_verified', null, $m['company_id'], ['sha256' => hash_file('sha256', $backup),'target_prefix' => $prefix]);
            return $m;
        } finally {
            $zip->close();
            @unlink($tmp);
        }
    }
}
