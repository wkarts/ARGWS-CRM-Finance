<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('MULTIEMPRESA_TEST_DISPOSABLE')!=='1'){exit(1);}
require getenv('MULTIEMPRESA_HOST').'/modules/multiempresa/autoload.php';
use Multiempresa\Application\{App,CompanySwitch,Installation};
use Multiempresa\Domain\Failure;
$app=App::environment();$actor=$app->auth->syncNative(1);$a=$app->catalog->bySlug('grupo','alfa');$b=$app->catalog->bySlug('grupo','beta');$installation=new Installation($app);$original=$installation->get()['organization_id'];
$other=$app->catalog->createOrganization($actor,'Escopo transitório','escopo-transitorio');$count=0;
function deniedScope(callable $fn,string $label):void{global $count;try{$fn();}catch(Failure $e){if(!in_array($e->reason,['organization_scope','switch_expired'],true)){throw $e;}$count++;echo 'PASS organization '.$label."\n";return;}throw new RuntimeException($label);}
try{
    $app->db->exec('UPDATE pme_organizations SET active=0 WHERE id=?',[$original]);
    (new \Multiempresa\Application\IdentityCleanup($app))->reconcile();
    if(!$app->db->one('SELECT user_id FROM pme_memberships WHERE user_id=? AND company_id=?',[$actor['id'],$a['id']])||$app->catalog->accessible($actor['id'])){throw new RuntimeException('Suspension must deny entry without erasing ownership');}
    $count++;echo "PASS organization temporary suspension preserves scoped grants while denying entry\n";
    $app->db->exec('UPDATE pme_organizations SET active=1 WHERE id=?',[$original]);
    $app->db->exec("UPDATE pme_companies SET status='active' WHERE id=?",[$b['id']]);
    $session=$app->auth->createSession($actor,$a['id'],false);$s=$app->auth->session($session['token'],$a['id']);$switch=new CompanySwitch($app);
    $ticket=$switch->issue($s,$a['id'],$b['id']);$app->db->exec('UPDATE pme_companies SET organization_id=? WHERE id=?',[$other,$b['id']]);
    deniedScope(fn()=>$switch->authorize($s,$ticket,hash('sha256','verifier')),'destination organization is rechecked when ticket is consumed');
    $app->db->exec('UPDATE pme_companies SET organization_id=? WHERE id=?',[$b['organization_id'],$b['id']]);
    $ticket=$switch->issue($s,$a['id'],$b['id']);$app->db->exec('UPDATE pme_companies SET organization_id=? WHERE id=?',[$other,$a['id']]);
    deniedScope(fn()=>$switch->authorize($s,$ticket,hash('sha256','verifier')),'source organization is rechecked when ticket is consumed');
    $app->db->exec('UPDATE pme_companies SET organization_id=? WHERE id=?',[$a['organization_id'],$a['id']]);
    $installation->configure($actor,$a['organization_id']);$ticket=$installation->returnRequest($s,$a['id']);$installation->configure($actor,'');
    deniedScope(fn()=>$installation->returnToNative($actor,$ticket),'unlinked primary rejects a previously issued return ticket');
}finally{
    $app->db->exec('UPDATE pme_organizations SET active=1 WHERE id=?',[$original]);
    $app->db->exec('UPDATE pme_companies SET organization_id=?,status=? WHERE id=?',[$a['organization_id'],$a['status'],$a['id']]);
    $app->db->exec('UPDATE pme_companies SET organization_id=?,status=? WHERE id=?',[$b['organization_id'],$b['status'],$b['id']]);
    $installation->configure($actor,$original);
    foreach([$a,$b] as $c){$app->catalog->grant($actor,$c['id'],$actor['id'],1);}
}
echo 'RESULT '.$count." ticket scope regression assertions passed\n";
