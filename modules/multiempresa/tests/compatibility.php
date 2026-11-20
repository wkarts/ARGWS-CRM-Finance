<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require getenv('MULTIEMPRESA_HOST').'/modules/multiempresa/autoload.php';
use Multiempresa\Application\App;
use Multiempresa\Perfex\SchemaTemplate;
use Multiempresa\Domain\Failure;
$app=App::environment();$source=$app->db->pdo;$n=0;
function compat_check(bool $ok,string $label): void {global $n;if(!$ok){throw new RuntimeException('FAIL '.$label);}$n++;echo 'PASS '.$label."\n";}
$core=(int)$source->query('SELECT version FROM `'.$app->config['native_prefix'].'migrations`')->fetchColumn();
$plan=SchemaTemplate::capture($source,$app->config['native_prefix'],$core);
$name=$source->query('SELECT DATABASE()')->fetchColumn().'_compat';
$source->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$source->exec('USE `'.$name.'`');
try {
    SchemaTemplate::apply($source,'legacy_',$plan);
    $source->exec('ALTER TABLE legacy_itemable DROP COLUMN is_optional, DROP COLUMN is_selected');
    $source->exec('ALTER TABLE legacy_project_notes DROP COLUMN title, DROP COLUMN dateadded');
    $source->exec('ALTER TABLE legacy_templates DROP COLUMN content_type');
    $source->exec("INSERT INTO legacy_clients(company,datecreated,active) VALUES('CONFIDENTIAL CLIENT',NOW(),1)");
    $source->exec("UPDATE legacy_options SET value='CONFIDENTIAL CONFIG' WHERE name IN ('smtp_password','smtp_host','companyname')");
    $source->exec("INSERT INTO legacy_modules(module_name,installed_version,active) VALUES('unreviewed','1.0.0',1)");
    $source->exec("CREATE TABLE legacy_private_module(id INT PRIMARY KEY, secret TEXT)");
    $source->exec("DELETE FROM legacy_options WHERE name LIKE 'ai_%' OR name LIKE 'openai_%'");
    // A missing optional seed column is also covered independently of version labels.
    $source->exec('ALTER TABLE legacy_emailtemplates DROP COLUMN fromemail');
    foreach(range(320,329) as $version) {
        $source->exec('UPDATE legacy_migrations SET version='.$version);
        $legacy=SchemaTemplate::capture($source,'legacy_',$version);
        compat_check((int)$legacy['tables']['migrations']['rows'][0]['version']===$version,'3.2.'.($version-320).' schema version accepted without patch whitelist');
        compat_check(!str_contains($legacy['tables']['itemable']['ddl'],'is_optional'),'3.2.'.($version-320).' installed structure retained');
        if(!in_array($version,[320,329],true)){continue;}
        $target='v'.$version.'_';SchemaTemplate::apply($source,$target,$legacy);
        compat_check((int)$source->query('SELECT version FROM '.$target.'migrations')->fetchColumn()===$version,'native version preserved during provisioning '.$version);
        compat_check((int)$source->query('SELECT COUNT(*) FROM '.$target.'clients')->fetchColumn()===0,'business records not cloned '.$version);
        compat_check((int)$source->query('SELECT COUNT(*) FROM '.$target.'modules')->fetchColumn()===0,'module activation not inherited '.$version);
        compat_check((int)$source->query("SELECT COUNT(*) FROM ".$target."options WHERE value='CONFIDENTIAL CONFIG'")->fetchColumn()===0,'configuration secrets not cloned '.$version);
        compat_check((int)$source->query("SELECT COUNT(*) FROM ".$target."options WHERE name LIKE 'ai_%' OR name LIKE 'openai_%'")->fetchColumn()===0,'later option names not added '.$version);
        compat_check((int)$source->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$target."private_module'")->fetchColumn()===0,'external module tables excluded '.$version);
        compat_check((int)$source->query('SELECT COUNT(*) FROM '.$target.'emailtemplates')->fetchColumn()>0,'clean seeds tolerate optional column absence '.$version);
    }
    $source->exec('ALTER TABLE legacy_clients ADD COLUMN custom_reference INT NULL, ADD CONSTRAINT legacy_external_fk FOREIGN KEY (custom_reference) REFERENCES legacy_private_module(id)');
    $dependencies=SchemaTemplate::capture($source,'legacy_',329);
    compat_check(count($dependencies['deferred_constraints'])===1,'custom module foreign key is deferred instead of blocking initial provisioning');
    SchemaTemplate::apply($source,'dep_',$dependencies);
    $ddl=$source->query('SHOW CREATE TABLE dep_clients')->fetch(PDO::FETCH_NUM)[1];
    compat_check(!str_contains($ddl,'legacy_private_module'),'new tenant has no foreign reference to central module data');
    compat_check((int)$source->query('SELECT COUNT(*) FROM dep_clients')->fetchColumn()===0,'foreign key adaptation copies no business records');
    try {SchemaTemplate::capture($source,'legacy_',320);throw new RuntimeException('Mismatch not blocked');}
    catch(Failure $e) {compat_check($e->reason==='schema_source_version','actual database/code mismatch still rejected');}
} finally {$source->exec('USE `'.getenv('MULTIEMPRESA_TEST_DATABASE').'`');}
$header=file_get_contents(dirname(__DIR__).'/multiempresa.php');
compat_check(str_contains($header,'Requires at least: 3.2.0'),'native module minimum is 3.2.0');
compat_check(str_contains($header,'Author: WWSoft -> Wallace Kleiton'),'native author follows provided model');
compat_check(str_contains($header,'Author URI: https://t.me/wkartspro'),'native developer link follows provided model');
echo 'RESULT '.$n." compatibility assertions passed (schema fixtures; not full 3.2.x distributions)\n";
