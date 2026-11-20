<?php

declare(strict_types=1);
namespace Multiempresa\Perfex;

use Multiempresa\Application\App;
use Multiempresa\Domain\{Failure,Id};
use Multiempresa\Infrastructure\{Files,Schema,Database};

final class Installer
{
    public static function moduleRoot(): string { return dirname(__DIR__, 2); }
    public static function root(): string { return dirname(self::moduleRoot(), 2); }
    public static function settings(): array { return require self::moduleRoot().'/config/settings.php'; }
    public static function configPath(): string
    {
        $settings = self::settings();
        $state = $settings['state_dir'] ?? dirname(self::root()).'/.multiempresa-'.substr(hash('sha256', self::root()), 0, 16);
        return rtrim($state, '/').'/installation.php';
    }
    public static function install(int $version = 0): void
    {
        if (defined('PME_CONTEXT_READY')) { return; }
        $version=$version ?: Version::number(Version::current());
        foreach (['pdo_mysql','mysqli','sodium','mbstring','zip','openssl','curl'] as $ext) {
            if (!extension_loaded($ext)) { throw new Failure('extension', 'Extensão PHP necessária: '.$ext, 503); }
        }
        $ci = get_instance();
        if (strlen(db_prefix())>16) { throw new Failure('prefix_length','O prefixo da instalação principal deve ter até 16 caracteres.'); }
        $root = realpath(FCPATH);
        if ($root !== realpath(self::root())) { throw new Failure('module_location', 'Instale em modules/multiempresa.'); }
        $configPath = self::configPath();
        Files::mkdir(dirname($configPath));
        $state = realpath(dirname($configPath));
        $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? FCPATH) ?: $root;
        foreach (array_unique([$root,$documentRoot]) as $public) {
            if ($state === $public || str_starts_with($state, $public.'/')) {
                throw new Failure('private_storage', 'Não foi possível preparar o armazenamento privado fora da raiz pública. Verifique as permissões de diretório da instalação.');
            }
        }
        $lock = fopen($state.'/install.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) { throw new Failure('install_lock', 'Outra instalação está em execução.'); }
        try {
            if (!is_file($configPath)) {
                $release = Adapter::inspect($root);
                $host = $ci->db->hostname;
                $port = (int)($ci->db->port ?? 3306);
                if (preg_match('/^([^:]+):([0-9]+)$/', $host, $m)) { $host=$m[1]; $port=(int)$m[2]; }
                $dsn = 'mysql:host='.$host.';port='.$port.';dbname='.$ci->db->database.';charset=utf8mb4';
                $options = [];
                if (is_array($ci->db->encrypt) && !empty($ci->db->encrypt['ssl_ca'])) {
                    $options[\PDO::MYSQL_ATTR_SSL_CA]=$ci->db->encrypt['ssl_ca'];
                    $options[\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]=true;
                }
                $settings = self::settings();
                $binary = $settings['php_binary'] ?? self::detectPhp();
                $config = [
                    'central'=>['dsn'=>$dsn,'username'=>$ci->db->username,'password'=>$ci->db->password,'options'=>$options,'table_prefix'=>db_prefix().'multiempresa_'],
                    'native_prefix'=>db_prefix(), 'root'=>$root, 'native_url'=>rtrim(APP_BASE_URL,'/').'/',
                    'base_url'=>rtrim(APP_BASE_URL,'/').'/modules/multiempresa/tenant.php',
                    'state_dir'=>$state,'master_key'=>base64_encode(random_bytes(32)),
                    'php_binary'=>$binary,'require_https'=>parse_url(APP_BASE_URL, PHP_URL_SCHEME)==='https',
                    'trusted_proxies'=>$settings['trusted_proxies'] ?? [],'session_ttl'=>28800,
                    'extensions_file'=>self::moduleRoot().'/config/extensions.php',
                ];
                Files::write($configPath, '<?php return '.var_export($config,true).';');
            }
            $app = App::environment();
            Schema::install($app->db, $version);
            if($version>=112){(new \Multiempresa\Application\GlobalSettings($app))->removeLegacyLookupSettings();}
            if($version>=110){$app->db->exec("DELETE FROM pme_modules WHERE LOWER(name)='multiempresa'");$app->db->exec("DELETE FROM pme_module_reviews WHERE LOWER(name)='multiempresa'");}
            if ($version >= 102 && !$app->db->one('SELECT id FROM pme_releases LIMIT 1')) {
                $id = (new Adapter($app))->register($root);
                $app->db->exec("UPDATE pme_gate SET state='active',release_id=? WHERE id=1",[$id]);
            }
            if($version>=120){
                (new \Multiempresa\Application\CatalogRemoval($app))->retireLegacyTombstones();
                FrontController::install($app);
                $nativeStaff=Id::identifier($app->config['native_prefix'].'staff');
                foreach($app->db->all('SELECT u.id,u.native_staff_id FROM pme_users u JOIN pme_user_preferences p ON p.user_id=u.id WHERE p.disabled_on_uninstall=1 AND u.platform_admin=0') as $user){
                    $q=$app->db->pdo->prepare('UPDATE `'.$nativeStaff.'` SET active=1 WHERE staffid=? AND admin=0');$q->execute([$user['native_staff_id']]);
                    (new \Multiempresa\Application\StaffAccess($app))->savePreference($user['id'],['disabled_on_uninstall'=>0]);
                }
                $routes=new \Multiempresa\Application\CompanyRoutes($app);$routes->key(\Multiempresa\Application\Installation::ID);
                foreach($app->db->all('SELECT id FROM pme_companies') as $company){$routes->key($company['id']);}
            }
            if($version>=124){(new \Multiempresa\Application\IdentityCleanup($app))->reconcile();}
            if($version>=113){
                try{(new \Multiempresa\Application\CoreMaintenance($app))->synchronize();}
                catch(\Throwable $e){$app->audit->log('core.synchronization_pending',null,null,['reason'=>$e instanceof Failure?$e->reason:'core_sync']);}
                foreach($app->db->all("SELECT id,company_id,steps,step_index FROM pme_onboardings WHERE status IN ('failed','interrupted') AND error_code IN ('core_changed','release_changed')") as $w){
                    $steps=json_decode($w['steps'],true,64,JSON_THROW_ON_ERROR);$steps[(int)$w['step_index']]['status']='pending';
                    $app->db->exec("UPDATE pme_onboardings SET status='pending',error_code=NULL,steps=? WHERE id=?",[json_encode($steps,JSON_THROW_ON_ERROR),$w['id']]);
                    $app->db->exec("UPDATE pme_companies SET status=CASE WHEN schema_version=0 THEN 'provisioning' ELSE 'suspended' END,error_code=NULL WHERE id=?",[$w['company_id']]);
                }
            }
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
    public static function detectPhp(): string
    {
        foreach(array_unique([PHP_SAPI==='cli'?PHP_BINARY:'',PHP_BINDIR.'/php',PHP_BINDIR.'/php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,'/usr/bin/php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,'/usr/bin/php','/usr/local/bin/php']) as $path){
            if($path!==''&&is_file($path)&&is_executable($path)&&!str_contains(basename($path),'fpm')){return $path;}
        }
        return '';
    }
    public static function runtimeStatus(App $app): array
    {
        return ['php_binary'=>$app->config['php_binary']??'','trusted_proxies'=>$app->config['trusted_proxies']??[],
            'process_available'=>function_exists('proc_open'),'symlink_available'=>function_exists('symlink'),
            'private_storage_writable'=>is_writable($app->state()),'php_available'=>is_executable($app->config['php_binary']??'')];
    }
    public static function configureRuntime(App $app,array $input): void
    {
        $path=trim((string)($input['php_binary']??''));if($path===''){$path=self::detectPhp();}
        if($path!==''&&(!str_starts_with($path,'/')||!is_file($path)||!is_executable($path))){throw new Failure('php_cli','Selecione um executável PHP CLI válido nas configurações do módulo.');}
        if($path!==''&&function_exists('proc_open')){
            \Multiempresa\Infrastructure\Process::run([$path,'-r','echo json_encode(["ok"=>PHP_SAPI==="cli" && PHP_VERSION_ID>=80100 && extension_loaded("pdo_mysql") && extension_loaded("sodium")]);'],[],10);
        }
        $proxies=$input['trusted_proxies']??[];if(is_string($proxies)){$proxies=array_filter(array_map('trim',explode(',',$proxies)));}
        if(!is_array($proxies)||count($proxies)>32){throw new Failure('proxy','Informe até 32 endereços IP de proxies confiáveis.');}
        foreach($proxies as $ip){if(!is_string($ip)||!filter_var($ip,FILTER_VALIDATE_IP)){throw new Failure('proxy','Endereço IP de proxy inválido.');}}
        $file=self::configPath();$config=require $file;$config['php_binary']=$path;$config['trusted_proxies']=array_values($proxies);
        Files::write($file,'<?php return '.var_export($config,true).';');
    }
    public static function assertEnabled(App $app): void
    {
        $table=Id::identifier($app->config['native_prefix'].'modules');
        $q=$app->db->pdo->prepare('SELECT active FROM `'.$table.'` WHERE module_name=?');
        $q->execute(['multiempresa']);
        if ((int)$q->fetchColumn()!==1) { throw new Failure('module_disabled','O módulo Multiempresa está desativado.',503); }
    }
}
