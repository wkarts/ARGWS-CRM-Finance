<?php
declare(strict_types=1);
require __DIR__.'/../autoload.php';
use Multiempresa\Application\{App,CompanyRoutes,Installation};
use Multiempresa\Infrastructure\{Files,Schema};
use Multiempresa\Perfex\FrontController;
$n=0;function routeCheck(bool $ok,string $label):void{global $n;if(!$ok){throw new RuntimeException($label);}echo 'PASS routes '.$label."\n";$n++;}
$root=sys_get_temp_dir().'/pme-routes-'.bin2hex(random_bytes(6));Files::mkdir($root.'/application/config');
$native="<?php\ndeclare(strict_types=1);\n// Native file must remain unchanged outside the owned block.\necho 'native';\n";
Files::write($root.'/index.php',$native);
$hooks="<?php\n\$hook['pre_system'][] = static function () {};\n?>\n";Files::write($root.'/application/config/my_hooks.php',$hooks);
$app=new App(['central'=>['dsn'=>'sqlite::memory:'],'root'=>$root,'state_dir'=>$root.'-state','master_key'=>base64_encode(random_bytes(32)),'native_url'=>'https://example.test/crm/','base_url'=>'https://example.test/crm/modules/multiempresa/tenant.php']);Schema::install($app->db,120);
$routes=new CompanyRoutes($app);$key=$routes->key(Installation::ID);routeCheck(preg_match('/\A[a-f0-9]{20}\z/',$key)===1,'primary receives random opaque identifier');
routeCheck($routes->key(Installation::ID)===$key,'primary identifier is persistent');
FrontController::install($app);$one=file_get_contents($root.'/index.php');$hookOne=file_get_contents($root.'/application/config/my_hooks.php');
routeCheck(strpos($one,'declare(strict_types=1)')<strpos($one,'BEGIN MULTIEMPRESA'),'strict_types remains first statement');
FrontController::install($app);routeCheck(file_get_contents($root.'/index.php')===$one&&file_get_contents($root.'/application/config/my_hooks.php')===$hookOne,'repeated integration is idempotent');
routeCheck($routes->url(Installation::ID)==='https://example.test/crm/'.$key.'/','subdirectory URLs contain no organization or transport query');
routeCheck($routes->resolve($key)===Installation::ID&&$routes->resolve(str_repeat('f',20))===null&&$routes->resolve('../')===null,'route lookup rejects unknown and malformed identifiers');
file_put_contents($root.'/index.php',"// Administrator edit retained.\n",FILE_APPEND);
FrontController::remove($app);routeCheck(file_get_contents($root.'/index.php')===$native."// Administrator edit retained.\n",'removal preserves unrelated later native edits');
routeCheck(file_get_contents($root.'/application/config/my_hooks.php')===$hooks,'existing hook content restored byte for byte');
$other=$root.'-linked.php';Files::write($other,$native);unlink($root.'/index.php');symlink($other,$root.'/index.php');FrontController::install($app);
routeCheck(!is_link($root.'/index.php')&&file_get_contents($other)===$native,'integration detaches symlinks without modifying their target');
echo 'RESULT '.$n." routes assertions passed\n";
