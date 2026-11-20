<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('MULTIEMPRESA_TEST_DISPOSABLE')!=='1'){exit(1);}
require getenv('MULTIEMPRESA_HOST').'/modules/multiempresa/autoload.php';
use Multiempresa\Application\{App,Modules};use Multiempresa\Infrastructure\Files;use Multiempresa\Domain\Failure;
$app=App::environment();$c=$app->catalog->bySlug('grupo','alfa');$m=new Modules($app);$root=$app->config['root'];$name='toggle_probe';$n=0;
function toggletest(bool $ok,string $label):void{global $n;if(!$ok){throw new RuntimeException('FAIL toggle '.$label);}$n++;echo 'PASS toggle '.$label."\n";}
Files::write($root.'/modules/'.$name.'/'.$name.'.php',<<<'MODULE'
<?php
/*
Module Name: Toggle regression probe
Version: 1.0.0
*/
register_activation_hook('toggle_probe',static function(){if(get_option('pme_toggle_fail')==='1'){throw new RuntimeException('Deliberate toggle failure');}});
register_deactivation_hook('toggle_probe',static function(){if(get_option('pme_toggle_fail')==='2'){throw new RuntimeException('Deliberate deactivation failure');}});
MODULE);
$m->approve($c['id'],$name,true);$m->disable($c['id'],$name);
$before=glob($app->state('backups/'.$c['id'].'/*'));$workflow=$app->db->one('SELECT status,step_index FROM pme_onboardings WHERE company_id=?',[$c['id']]);
$pdo=$app->storage($c['mode'])->connect($app->vault->open($c['secrets'])['connection']);$prefix=$c['table_prefix'];$pdo->exec("INSERT INTO `".$prefix."options`(name,value,autoload) VALUES('pme_toggle_fail','1',0)");
try{$m->approve($c['id'],$name,true);throw new RuntimeException('Expected failure');}catch(Failure $e){toggletest($e->reason==='tenant_failed','native activation hook failure reported');}
toggletest($app->catalog->company($c['id'])['status']===$c['status'],'failed optional reactivation restores provisioned company status');
toggletest((int)$pdo->query("SELECT active FROM `".$prefix."modules` WHERE module_name='toggle_probe'")->fetchColumn()===0,'failed extension stays disabled natively');
toggletest((int)$app->db->one('SELECT active FROM pme_modules WHERE company_id=? AND name=?',[$c['id'],$name])['active']===0,'failed extension stays disabled centrally');
toggletest(glob($app->state('backups/'.$c['id'].'/*'))===$before,'state-only failure creates no full backup');
toggletest($workflow===$app->db->one('SELECT status,step_index FROM pme_onboardings WHERE company_id=?',[$c['id']]),'state change never changes onboarding progress');
$pdo->exec("UPDATE `".$prefix."options` SET value='0' WHERE name='pme_toggle_fail'");$m->approve($c['id'],$name,true);
toggletest(glob($app->state('backups/'.$c['id'].'/*'))===$before,'successful retry remains a state toggle');
$pdo->exec("UPDATE `".$prefix."options` SET value='2' WHERE name='pme_toggle_fail'");try{$m->disable($c['id'],$name);throw new RuntimeException('Expected failure');}catch(Failure $e){toggletest($e->reason==='tenant_failed','native deactivation hook failure reported');}
toggletest($app->catalog->company($c['id'])['status']===$c['status'],'deactivation failure does not strand entire site in maintenance');
$pdo->exec("DELETE FROM `".$prefix."options` WHERE name='pme_toggle_fail'");
$first='first_activation_probe';Files::write($root.'/modules/'.$first.'/'.$first.'.php',<<<'MODULE'
<?php
/*
Module Name: First activation failure probe
Version: 1.0.0
*/
register_activation_hook('first_activation_probe',static function(){update_option('pme_first_activation_write','must be rolled back');throw new RuntimeException('Deliberate first activation failure');});
MODULE);
$m->makeAvailable($c['id'],$first,true);$before=glob($app->state('backups/'.$c['id'].'/*'));
try{$m->approve($c['id'],$first,true);throw new RuntimeException('Expected failure');}catch(Failure $e){toggletest($e->reason==='tenant_failed','failed first manual activation reports the original hook error');}
toggletest($app->catalog->company($c['id'])['status']===$c['status'],'failed first manual activation restores the usable company');
toggletest((int)$pdo->query("SELECT COUNT(*) FROM `".$prefix."options` WHERE name='pme_first_activation_write'")->fetchColumn()===0,'failed first activation rolls back installer data');
toggletest(count(glob($app->state('backups/'.$c['id'].'/*')))===count($before)+1,'first manual activation retains its safety backup');
echo 'RESULT '.$n." module state assertions passed\n";
