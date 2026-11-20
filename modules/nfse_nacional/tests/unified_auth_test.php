<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
define('BASEPATH', __DIR__);
require dirname(__DIR__) . '/autoload.php';
use NfseNacional\{Config,UnifiedAuth,EnvironmentAuth};
$n=0; function ua($yes,$msg){global $n;if(!$yes)throw new RuntimeException($msg);++$n;}
$settings=Config::defaults();$settings['environment']='2';$settings['auth'][1]['method']='username_password';
$a=['pfx'=>'older-one','password'=>'one'];$b=['pfx'=>'older-two','password'=>'two'];
$material=[1=>['certificate'=>$a,'login'=>['username'=>'prod','password'=>'password1']],2=>['certificate'=>$b,'login'=>['username'=>'test','password'=>'password2']]];
[$s,$m,$shared]=UnifiedAuth::plan($settings,$material,null);
ua($s['auth_certificate_conflict']==='1' && $shared===null,'different certificates require explicit selection');
ua($m[1]===$material[1] && $m[2]===$material[2],'all legacy credentials remain unchanged');
ua($m['legacy_profiles']===$settings['auth'],'different legacy methods retained');
ua(!isset($m['shared_login']),'different legacy logins not silently merged');
ua($s['auth_method']==='certificate','active environment method is preserved in unified profile');
try{EnvironmentAuth::profile($s,'2');throw new LogicException('should reject');}catch(RuntimeException $e){ua(str_contains($e->getMessage(),'certificados anteriores'),'clear conflict limitation');}
$s['auth_certificate_conflict']='0';
foreach(['1','2'] as $env){ua(EnvironmentAuth::profile($s,$env)['method']==='certificate','one shared method for '.$env);EnvironmentAuth::assertApiSupported($s,$env);}
$s['auth_method']='username_password';
foreach(['1','2'] as $env){try{EnvironmentAuth::assertApiSupported($s,$env);throw new LogicException('should reject');}catch(RuntimeException $e){ua(str_contains($e->getMessage(),'Nenhuma credencial foi enviada'),'unsupported password never falls back in '.$env);}}
[$s,$m,$shared]=UnifiedAuth::plan($settings,[1=>['certificate'=>$a],2=>['certificate'=>$a]],$a);
ua($s['auth_certificate_conflict']==='0'&&$shared===$a,'identical certificates become shared without conflict');
ua($m['legacy_local_certificate']===$a,'old local certificate retained');
$new=EnvironmentAuth::prepareMaterial($material,['auth_username'=>'new','auth_password'=>'shared'],[],'11222333000181');
ua($new[1]['login']===$material[1]['login']&&$new[2]['login']===$material[2]['login'],'new shared login does not erase old different values');
$public=EnvironmentAuth::publicState($new);ua(!str_contains(json_encode($public),'password1')&&!str_contains(json_encode($public),'older-one'),'public state contains no secret');
ua(UnifiedAuth::plan($s,$m,$shared)===[$s,$m,null],'repeat migration is idempotent');
$fixed=Config::defaults();$fixed['tax']['simple_rate']='4.25';$fixed['tax']['total_tax_mode']='amount';
$enabled=Config::sanitize(['variable_simple_rate'=>'1','tax'=>['simple_rate'=>'99.99','total_tax_mode'=>'simple']],$fixed);
ua($enabled['tax']['simple_rate']==='4.25'&&$enabled['tax']['total_tax_mode']==='simple','variable rate preserves fixed percentage and accepts independent estimation method');
echo "OK: $n verificações de autenticação compartilhada e preservação das credenciais anteriores.\n";
