<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/autoload.php';
use Multiempresa\Application\{App,IdentityCleanup,StaffAccess};
use Multiempresa\Infrastructure\{Schema,Files};
use Multiempresa\Domain\Id;

// A missing owner must never be reconstructed from a stale cross-group grant.
$state=sys_get_temp_dir().'/multiempresa-identity-'.Id::new();Files::mkdir($state);
$app=new App(['central'=>['dsn'=>'sqlite::memory:','table_prefix'=>'core_multiempresa_'],'native_prefix'=>'core_','master_key'=>base64_encode(random_bytes(32)),'state_dir'=>$state,'base_url'=>'https://example.test/tenant.php','native_url'=>'https://example.test/']);
Schema::install($app->db,124);
$app->db->pdo->exec('CREATE TABLE core_staff(staffid INTEGER PRIMARY KEY,email TEXT,firstname TEXT,lastname TEXT,password TEXT,active INT,admin INT,two_factor_auth_enabled INT)');
$app->db->pdo->exec('CREATE TABLE core_options(name TEXT,value TEXT)');
$app->db->pdo->exec("INSERT INTO core_staff VALUES(1,'principal@example.test','Principal','','test',1,1,0),(2,'orphan@example.test','Orphan','','test',1,0,0)");
$principal=$app->auth->syncNative(1);$orphan=$app->auth->syncNative(2);
$org=Id::new();$company=Id::new();$missing=Id::new();
$app->db->exec('INSERT INTO pme_organizations(id,slug,name) VALUES(?,?,?)',[$org,'foreign','Foreign']);
$app->db->exec('INSERT INTO pme_companies(id,organization_id,slug,name,mode,table_prefix,storage_identity,secrets,status,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[$company,$org,'foreign-company','Foreign company','database','isolated_',Id::new(),$app->vault->seal([]),'active',time()]);
(new StaffAccess($app))->savePreference($orphan['id'],['identity_company'=>$missing,'principal_access'=>0,'last_company_id'=>$company]);
$app->db->exec('INSERT INTO pme_memberships(company_id,user_id,staff_id,active) VALUES(?,?,1,1)',[$company,$orphan['id']]);
$app->auth->createSession($orphan,$company,false);
$cleaner=new IdentityCleanup($app);$cleaner->reconcile();$n=0;
function cleanupCheck(bool $ok,string $label):void{global $n;if(!$ok){throw new RuntimeException($label);}$n++;echo 'PASS identity '.$label."\n";}
cleanupCheck(!$app->db->one('SELECT id FROM pme_users WHERE id=?',[$orphan['id']])&&!$app->db->pdo->query('SELECT staffid FROM core_staff WHERE staffid=2')->fetchColumn(),'orphan with a foreign grant is deleted instead of reassigned');
cleanupCheck(!$app->db->one('SELECT user_id FROM pme_memberships WHERE user_id=?',[$orphan['id']])&&!$app->db->one('SELECT user_id FROM pme_sessions WHERE user_id=?',[$orphan['id']]),'stale grant and authenticated session are removed');
cleanupCheck((bool)$app->db->one('SELECT id FROM pme_users WHERE id=?',[$principal['id']])&&(bool)$app->db->one('SELECT id FROM pme_companies WHERE id=?',[$company]),'pre-existing principal and foreign company are preserved');
$cleaner->reconcile();
cleanupCheck(count($app->db->all('SELECT id FROM pme_users'))===1,'repeated cleanup does not recreate an identity');
echo 'RESULT '.$n." identity cleanup assertions passed\n";
