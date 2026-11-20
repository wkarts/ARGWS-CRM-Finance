<?php

declare(strict_types=1);

namespace Multiempresa\Perfex;

use Multiempresa\Application\App;
use Multiempresa\Domain\{Context,Failure};
use Multiempresa\Infrastructure\Files;

final class Runtime
{
    public function __construct(private App $app)
    {
    }
    public function prepare(Context $ctx, array $release, bool $verifySources = false): array
    {
        if(!function_exists('symlink')){throw new Failure('symlink','O servidor não disponibiliza links simbólicos para o ambiente isolado.',503);}
        $root = realpath($release['root']);
        if (!$root) {
            throw new Failure('release_root', 'Código da versão não encontrado.', 503);
        }
        $base = $this->app->state('companies/'.$ctx->companyId);
        $runtime = $base.'/releases/'.$release['id'];
        Files::mkdir($runtime);
        $lock = fopen($runtime.'/prepare.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            Files::mkdir($runtime.'/application/config');
            Files::mkdir($runtime.'/public/modules');
            foreach (['uploads','media'] as $dir) {
                Files::mkdir($base.'/'.$dir);
                $this->link($base.'/'.$dir, $runtime.'/public/'.$dir);
            }
            foreach (['cache','logs','sessions'] as $dir) {
                Files::mkdir($runtime.'/'.$dir);
            }
            Files::mkdir($runtime.'/public/temp');
            $this->link($root.'/assets', $runtime.'/public/assets');
            foreach (new \DirectoryIterator($root.'/application') as $f) {
                if ($f->isDot() || in_array($f->getFilename(), ['config','cache','logs','controllers','libraries'], true)) {
                    continue;
                }
                $this->link($f->getPathname(), $runtime.'/application/'.$f->getFilename());
            }
            $this->link($runtime.'/cache', $runtime.'/application/cache');
            $this->link($runtime.'/logs', $runtime.'/application/logs');
            $this->privateNativeTree($root.'/application/controllers',$runtime.'/application/controllers');
            $this->privateNativeTree($root.'/application/libraries',$runtime.'/application/libraries');
            $this->link($runtime.'/application',$runtime.'/public/application');
            Files::mkdir($runtime.'/application/controllers/multiempresa');
            foreach(['Worker','Api'] as $controller){$this->link(dirname(__DIR__,2).'/controllers/'.$controller.'.php',$runtime.'/application/controllers/multiempresa/'.$controller.'.php');}
            foreach (new \DirectoryIterator($root.'/application/config') as $f) {
                if ($f->isDot() || in_array($f->getFilename(), ['app-config.php','my_hooks.php','database.php'], true)) {
                    continue;
                }
                $this->link($f->getPathname(), $runtime.'/application/config/'.$f->getFilename());
            }
            $marker = "<?php\n// Generated: credentials live only in the immutable request context.\nif (!defined('PME_CONTEXT_READY')) { http_response_code(503); exit; }\n";
            if (!is_file($runtime.'/application/config/app-config.php')) {
                Files::write($runtime.'/application/config/app-config.php', $marker);
            }
            $db = "<?php\nrequire ".var_export($root.'/application/config/database.php', true).";\n\$db['default']['port'] = \$GLOBALS['pme_context']->connection['port'] ?? 3306;\n\$db['default']['socket'] = \$GLOBALS['pme_context']->connection['socket'] ?? null;\n\$db['default']['save_queries'] = false;\n\$db['default']['db_debug'] = false;\n";
            if (!is_file($runtime.'/application/config/database.php')) {
                Files::write($runtime.'/application/config/database.php', $db);
            }
            $hooks = "<?php\n";
            if (is_file($root.'/application/config/my_hooks.php')) {
                $hooks .= 'require '.var_export($root.'/application/config/my_hooks.php', true).";\n";
            }
            $hooks .= "array_unshift(\$hook['pre_controller_constructor'], function () { \\Multiempresa\\Perfex\\Bridge::initialize(); });\n";
            if (!is_file($runtime.'/application/config/my_hooks.php') || file_get_contents($runtime.'/application/config/my_hooks.php')!==$hooks) {
                Files::write($runtime.'/application/config/my_hooks.php', $hooks);
            }
            $approved = $this->app->db->all('SELECT m.name,COALESCE(r.fingerprint,m.fingerprint) AS fingerprint,m.active FROM pme_modules m LEFT JOIN pme_module_reviews r ON r.company_id=m.company_id AND r.name=m.name AND r.release_id=? WHERE m.company_id=?', [$release['id'],$ctx->companyId]);
            $allowed = []; $active = [];
            foreach ($approved as $module) {
                if(strtolower($module['name'])==='multiempresa'){continue;}
                $path = $root.'/modules/'.$module['name'];
                $private=$base.'/modules/'.$module['name'];
                // Requests execute the approved private copy. Hash the shared package only when
                // building it or executing an installer/migration, never for every dashboard AJAX call.
                $ready=ModuleRuntime::ready($private,$ctx->prefix,$module['fingerprint']);
                $operation=$GLOBALS['pme_job']['operation']??'';
                $otherModule=in_array($operation,['module_update','module_activate','module_deactivate','module_migrate'],true)&&($GLOBALS['pme_job']['module']??'')!==$module['name'];
                $automaticCore=$operation==='migrate'&&!empty($GLOBALS['pme_job']['automatic']);
                if(!$ready||($verifySources&&!$otherModule&&!$automaticCore)){
                    if(!is_dir($path)||!hash_equals($module['fingerprint'],Files::treeHash($path))){
                        if((int)$module['active']||!$ready){throw new Failure('module_changed','Módulo alterado sem homologação: '.$module['name'],503);}
                    }else{(new ModuleRuntime($this->app))->mount($path,$private,$ctx->prefix,$module['fingerprint']);}
                }
                $mount=$runtime.'/public/modules/'.$module['name'];
                if((is_link($mount)&&readlink($mount)!==$private)||(!is_link($mount)&&is_file($mount.'/.pme-overlay.json'))){ModuleRuntime::remove($mount);}
                $this->link($private,$mount);
                $allowed[] = $module['name']; if((int)$module['active']){$active[]=$module['name'];}
            }
            (new ModuleExtensions($this->app))->reconcile($ctx->companyId,$release,$active);
            foreach (new \DirectoryIterator($runtime.'/public/modules') as $f) {
                if (!$f->isDot() && !in_array($f->getFilename(), $allowed, true) && ($f->isLink()||is_file($f->getPathname().'/.pme-overlay.json'))) {
                    ModuleRuntime::remove($f->getPathname());
                }
            }
            // A module-owned controller replaces only the company's private native Modules screen.
            $target=$runtime.'/application/controllers/admin/Mods.php';
            $source=dirname(__DIR__,2).'/controllers/TenantMods.php';
            // Keep the source location so the view is resolved inside the module, never the private native tree.
            $this->ownedController($source,$target);
            return ['public' => $runtime.'/public/','application' => $runtime.'/application/','root' => $root,'cache' => $runtime.'/cache/','logs' => $runtime.'/logs/','sessions' => $runtime.'/sessions/'];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
    private function ownedController(string $source,string $target): void
    {
        $code=str_replace("dirname(__DIR__).'/views/tenant_modules.php'",var_export(dirname(__DIR__,2).'/views/tenant_modules.php',true),file_get_contents($source));
        if(is_link($target)||!is_file($target)||file_get_contents($target)!==$code){Files::write($target,$code);}
    }
    private function privateNativeTree(string $source,string $target): void
    {
        // These extension points are written by some native module installers. Never expose the main tree to writes.
        if(is_file($target.'/.pme-private-native')){return;}
        if(is_link($target)){unlink($target);}Files::mkdir($target);
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source,\FilesystemIterator::SKIP_DOTS|\FilesystemIterator::FOLLOW_SYMLINKS),\RecursiveIteratorIterator::SELF_FIRST) as $file){
            $path=$target.'/'.substr($file->getPathname(),strlen($source)+1);
            if(is_link($path)){unlink($path);}if($file->isDir()){Files::mkdir($path);}else{Files::write($path,file_get_contents($file->getPathname()));}
        }
        Files::write($target.'/.pme-private-native','1');
    }
    private function link(string $target, string $path): void
    {
        if (is_link($path)) {
            if (readlink($path) !== $target) {
                throw new Failure('runtime_changed', 'Link de execução inesperado.', 503);
            }
            return;
        }
        if (file_exists($path) || !symlink($target, $path)) {
            throw new Failure('runtime_link', 'Não foi possível preparar o ambiente isolado.', 503);
        }
    }
    public function run(Context $ctx, array $release, bool $worker = false): void
    {
        global $BM,$EXT,$CFG,$UNI,$URI,$RTR,$OUT,$SEC,$IN,$LANG,$hooks;
        if (defined('PME_CONTEXT_READY')) {
            throw new Failure('immutable_context', 'Um processo não pode executar duas empresas.', 500);
        }
        $paths = $this->prepare($ctx, $release, $worker);
        $c = $ctx->connection;
        $GLOBALS['pme_app'] = $this->app;
        $GLOBALS['pme_context'] = $ctx;
        $GLOBALS['pme_worker'] = $worker;
        define('PME_CONTEXT_READY', true);
        define('ENVIRONMENT', 'production');
        define('SELF', 'index.php');
        define('BASEPATH', $paths['root'].'/system/');
        define('FCPATH', $paths['public']);
        define('SYSDIR', 'system');
        define('APPPATH', $paths['application']);
        define('VIEWPATH', APPPATH.'views/');
        define('APP_CHMOD_DIR', 0700);
        define('APP_CHMOD_FILE', 0600);
        define('SHOW_DEBUG_BACKTRACE', false);
        ini_set('display_errors', '0');
        $constants = ['APP_BASE_URL' => $ctx->baseUrl,'APP_ENC_KEY' => $ctx->encryptionKey,'APP_DB_HOSTNAME' => $c['host'],'APP_DB_USERNAME' => $c['username'],'APP_DB_PASSWORD' => $c['password'],'APP_DB_NAME' => $c['database'],'APP_DB_PREFIX' => $ctx->prefix,'APP_DB_CHARSET' => 'utf8mb4','APP_DB_COLLATION' => 'utf8mb4_unicode_ci','SESS_DRIVER' => 'files','SESS_SAVE_PATH' => $paths['sessions'],'APP_SESSION_COOKIE_NAME' => 'pme_ci_'.$ctx->companyId,'APP_SESSION_COOKIE_SAME_SITE' => 'Lax','APP_COOKIE_PATH' => parse_url($ctx->baseUrl, PHP_URL_PATH) ?: '/','APP_COOKIE_SECURE' => $this->app->config['require_https'] ?? true,'APP_CSRF_PROTECTION' => true,'APP_CSRF_COOKIE_NAME' => 'pme_csrf_'.$ctx->companyId,'APP_COOKIE_PREFIX' => 'pme_'.$ctx->companyId.'_'];
        foreach ($constants as $key => $value) {
            define($key, $value);
        }
        if (!empty($c['ssl_ca'])) {
            define('APP_DB_ENCRYPT', ['ssl_ca' => $c['ssl_ca'],'ssl_verify' => true]);
        }
        $assign_to_config = ['cache_path' => $paths['cache'],'log_path' => $paths['logs'],'cookie_httponly' => true,'uri_protocol' => 'REQUEST_URI','modules_locations' => [$paths['public'].'modules/' => '../../public/modules/']];
        // URI parser strips SCRIPT_NAME's directory. Give it the virtual contextual directory,
        // preserving REQUEST_URI and query parameters for native links and callbacks.
        require_once __DIR__.'/url_bridge.php';
        $_SERVER['SCRIPT_NAME'] = \Multiempresa\Http\Entry::path($ctx->baseUrl).'index.php';
        $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
        $_SERVER['REQUEST_URI'] = \Multiempresa\Http\Entry::path($ctx->baseUrl).$ctx->route.(!empty($_SERVER['QUERY_STRING']) ? '?'.$_SERVER['QUERY_STRING'] : '');
        chdir(FCPATH);
        date_default_timezone_set('UTC');
        if ($worker) {
            $_SERVER['argv'] = ['index.php','multiempresa','worker','index'];
        }
        // CI3/HMVC rebinds global variables by reference. The entry script must include
        // CodeIgniter.php in GLOBAL scope, never from this method's local symbol table.
        $GLOBALS['pme_assign_to_config'] = $assign_to_config;
        $GLOBALS['pme_bootstrap_ready'] = true;
    }
}
