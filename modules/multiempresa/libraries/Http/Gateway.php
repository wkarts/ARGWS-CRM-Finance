<?php

declare(strict_types=1);

namespace Multiempresa\Http;

use Multiempresa\Application\{App,Events,ReleaseGate};
use Multiempresa\Domain\{Context,Id,Failure};
use Multiempresa\Infrastructure\Files;
use Multiempresa\Perfex\{Runtime,Adapter};

final class Gateway
{
    public function __construct(private App $app)
    {
    }
    public function handle(): void
    {
        \Multiempresa\Perfex\Installer::assertEnabled($this->app);
        $tls = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        if (!$tls && in_array($_SERVER['REMOTE_ADDR'] ?? '', $this->app->config['trusted_proxies'] ?? [], true)) {
            $tls = ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        }
        if ($this->secure() && !$tls) {
            throw new Failure('https_required', 'Esta instalação exige HTTPS.', 400);
        }
        if ($this->secure()) {
            header('Strict-Transport-Security: max-age=31536000');
        }
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: SAMEORIGIN');
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $ctx = (new Resolver($this->app))->resolve($host, $uri);
        $path = parse_url($uri, PHP_URL_PATH);
        $mount=rtrim(parse_url($this->app->config['base_url'],PHP_URL_PATH) ?? '', '/');
        $centralHost=parse_url($this->app->config['base_url'],PHP_URL_HOST);$centralPort=parse_url($this->app->config['base_url'],PHP_URL_PORT);
        if(strtolower($host)===strtolower($centralHost.($centralPort?':'.$centralPort:''))){$path=substr($path,strlen($mount)) ?: '/';}
        if ($ctx) {
            $canonical = $this->app->catalog->url($this->app->catalog->company($ctx->companyId));
            if ($canonical !== $ctx->baseUrl) {
                if ($method !== 'GET') {
                    throw new Failure('canonical_context', 'Abra a empresa pelo seu endereço canônico antes de enviar alterações.', 409);
                }
                Response::redirect($canonical.$ctx->route.(!empty($_SERVER['QUERY_STRING']) ? '?'.$_SERVER['QUERY_STRING'] : ''));
            }
        }
        if (!$ctx) {
            $organization=$this->app->db->one('SELECT organization_id FROM pme_organization_hosts WHERE host=? AND verified=1',[strtolower($host)]);
            if($organization&&parse_url($uri,PHP_URL_PATH)==='/') {Response::redirect($this->app->config['native_url'].'admin/multiempresa/organization_entry?id='.$organization['organization_id']);}
            if ($path === '/__health' && $method === 'GET') { Response::json(['module'=>'multiempresa','ready'=>true]); }
            if($organization){throw new Failure('organization_route','Informe o contexto da empresa nesta organização.',404);}
            if (preg_match('~\A/hooks/([a-z0-9_-]+)(?:/([a-f0-9]{32}))?\z~', $path, $m)) {
                if ($method !== 'POST') {
                    throw new Failure('method', 'Use POST.', 405);
                }
                $max = $this->app->config['max_body_bytes'] ?? 1048576;
                $body = file_get_contents('php://input', false, null, 0, $max + 1);
                Response::json((new Events($this->app))->receive($m[1], $m[2] ?? null, $this->headers(), $body));
            }
            if (preg_match('~\A/api/v1/(context|clients|invoices|events)(?:/([0-9]+))?\z~', $path, $m)) {
                $this->api($m[1], $m[2] ?? null, null);
                return;
            }
            throw new Failure('route','Página não encontrada.',404);
        }
        $actor = $this->app->auth->session($_COOKIE['pme_t_'.$ctx->companyId] ?? null, $ctx->companyId);
        if(str_starts_with($ctx->route,'admin')||str_starts_with($ctx->route,'__pme/')){header('Cache-Control: no-store, private');header('Pragma: no-cache');}
        $switch=new \Multiempresa\Application\CompanySwitch($this->app);
        if($ctx->route==='__pme/switch'){
            if($method!=='POST'||!$actor){throw new Failure('switch_session','Sessão ou método inválido.',403);}
            $this->app->auth->csrf($actor,(string)($_POST['csrf']??''));$target=(string)($_POST['company_id']??'');
            if($target===\Multiempresa\Application\Installation::ID){$request=(new \Multiempresa\Application\Installation($this->app))->returnRequest($actor,$ctx->companyId);Response::redirect($this->app->config['native_url'].'admin/multiempresa/return_to_installation?request='.rawurlencode($request));}
            $request=$switch->issue($actor,$ctx->companyId,$target);
            Response::redirect($this->app->catalog->url($this->app->catalog->company($target)).'__pme/switch_prepare?request='.rawurlencode($request));
        }
        if($ctx->route==='__pme/switch_prepare'){
            if($method!=='GET'){throw new Failure('method','Use GET.',405);}$raw=(string)($_GET['request']??'');$t=$switch->pending($raw);if($t['target_company']!==$ctx->companyId||$t['organization_id']!==$ctx->organizationId){throw new Failure('switch_scope','Seleção destinada a outra empresa.',403);}
            $verifier=Id::token();Response::cookie('pme_sso_'.$ctx->companyId,$verifier,parse_url($ctx->baseUrl,PHP_URL_PATH)?:'/',time()+120,$this->secure());
            Response::redirect($this->app->catalog->url($this->app->catalog->company($t['source_company'])).'__pme/switch_authorize?request='.rawurlencode($raw).'&challenge='.hash('sha256',$verifier));
        }
        if($ctx->route==='__pme/switch_authorize'){
            if($method!=='GET'||!$actor){throw new Failure('switch_session','A sessão de origem expirou.',403);}$result=$switch->authorize($actor,(string)($_GET['request']??''),(string)($_GET['challenge']??''));
            Response::cookie('pme_t_'.$ctx->companyId,'',parse_url($ctx->baseUrl,PHP_URL_PATH)?:'/',time()-3600,$this->secure());Response::redirect($this->app->catalog->url($result['company']).'__pme/callback?code='.rawurlencode($result['code']));
        }
        if ($ctx->route === '__pme/start') {
            if ($method !== 'GET') {
                throw new Failure('method', 'Use GET.', 405);
            }
            $verifier = Id::token();
            Response::cookie('pme_sso_'.$ctx->companyId, $verifier, parse_url($ctx->baseUrl, PHP_URL_PATH) ?: '/', time() + 180, $this->secure());
            $next=$this->nextRoute($_GET['next']??'admin');
            Response::cookie('pme_next_'.$ctx->companyId,$next,parse_url($ctx->baseUrl,PHP_URL_PATH)?:'/',time()+180,$this->secure());
            Response::redirect($this->app->config['native_url'].'admin/multiempresa/authorize?company='.$ctx->companyId.'&challenge='.hash('sha256', $verifier));
        }
        if ($ctx->route === '__pme/callback') {
            if ($method !== 'GET') {
                throw new Failure('method', 'Use GET.', 405);
            }
            $verifier = $_COOKIE['pme_sso_'.$ctx->companyId] ?? '';
            if (!$verifier) {
                throw new Failure('sso_cookie', 'Reinicie a seleção da empresa.', 403);
            }
            $s = $this->app->auth->consumeTicket((string)($_GET['code'] ?? ''), $ctx->companyId, $verifier);
            $cookiePath = parse_url($ctx->baseUrl, PHP_URL_PATH) ?: '/';
            Response::cookie('pme_t_'.$ctx->companyId, $s['token'], $cookiePath, $s['expires_at'], $this->secure());
            Response::cookie('pme_sso_'.$ctx->companyId, '', $cookiePath, time() - 3600, $this->secure());
            $next=$this->nextRoute($_COOKIE['pme_next_'.$ctx->companyId]??'admin');
            Response::cookie('pme_next_'.$ctx->companyId,'',$cookiePath,time()-3600,$this->secure());
            Response::redirect($ctx->baseUrl.$next);
        }
        if (preg_match('~\A/api/v1/(context|clients|invoices|events)(?:/([0-9]+))?\z~', '/'.$ctx->route, $m)) {
            $this->api($m[1], $m[2] ?? null, $ctx->companyId);
            return;
        }
        if (preg_match('~\A(?:admin/(?:auto_update)(?:/|$)|cron(?:/|$)|multiempresa/(?:worker|api)(?:/|$)|install(?:/|$))~i', $ctx->route)) {
            throw new Failure('central_operation', 'Esta rotina é controlada pela central Multiempresa.', 403);
        }
        if (preg_match('~\Aadmin(?:/|$)~i', $ctx->route) && !$actor) {
            Response::redirect($ctx->baseUrl.'__pme/start?next='.rawurlencode($ctx->route.(!empty($_SERVER['QUERY_STRING'])?'?'.$_SERVER['QUERY_STRING']:'')));
        }
        if (rtrim($ctx->route, '/') === 'admin/authentication/logout' && $actor) { Response::redirect($this->app->config['native_url'].'admin/authentication/logout'); }
        if (str_starts_with($ctx->route, 'admin/authentication')) {
            Response::redirect($ctx->baseUrl.'admin');
        }
        if (preg_match('~\A(?:application|system|temp|vendor)(?:/|$)~i', $ctx->route) || str_ends_with(strtolower($ctx->route), '.php')) {
            throw new Failure('private_path', 'Recurso indisponível.', 404);
        }
        if(preg_match('~\Aadmin/(?:modules|mods)(?:/(.*))?\z~i',$ctx->route,$moduleRoute)){
            $action=trim($moduleRoute[1]??'','/');
            if($action!==''&&$action!=='index'){
                if(!preg_match('~\A(activate|deactivate)/([a-z][a-z0-9_]*)\z~D',$action,$toggle)){
                    throw new Failure('central_operation','Instalar, remover ou atualizar módulos é permitido somente na empresa principal.',403);
                }
                if(!$actor){throw new Failure('module_access','Acesso não autorizado.',403);}
                if($method==='GET'){Response::redirect($ctx->baseUrl.'admin/modules');}
                if($method!=='POST'){throw new Failure('method','Use POST.',405);}
                $this->app->auth->csrf($actor,(string)($_POST['csrf']??''));
                try{(new \Multiempresa\Application\TenantModules($this->app))->change($ctx,$actor,$toggle[2],$toggle[1]==='activate');}
                catch(\Throwable $e){
                    try{(new \Multiempresa\Application\ExecutionLog($this->app))->record('module.toggle','failed',$actor['user_id'],$ctx->companyId,$ctx->organizationId,null,\Multiempresa\Application\ExecutionLog::exception($e)+['module'=>$toggle[2]]);}catch(\Throwable){error_log('Module action diagnosis could not be recorded.');}
                    if($e instanceof Failure&&$e->status===403){throw $e;}
                    $this->app->audit->log('module.toggle_failed',$actor['user_id'],$ctx->companyId,['module'=>$toggle[2],'reason'=>$e instanceof Failure?$e->reason:'module_failed']);
                    Response::redirect($ctx->baseUrl.'admin/modules?failed=1');
                }
                Response::redirect($ctx->baseUrl.'admin/modules?changed=1');
            }
        }
        $ctx = new Context($ctx->companyId, $ctx->organizationId, $ctx->mode, $ctx->prefix, $ctx->baseUrl, $ctx->route, $ctx->connection, $ctx->encryptionKey, $actor);
        // Branding and public assets do not execute native code or require a maintenance lease.
        // This also prevents a brief module toggle from breaking logos loaded by another tab.
        if($ctx->route==='__pme/branding'||preg_match('~\A(?:uploads/|assets/|modules/[a-z][a-z0-9_]*/assets/)~',$ctx->route)){
            $assetRelease=$this->app->db->one('SELECT r.* FROM pme_releases r JOIN pme_gate g ON g.release_id=r.id WHERE g.id=1');
            if($assetRelease&&$this->asset($ctx,$assetRelease)){return;}
        }
        $gate = new ReleaseGate($this->app);
        $release = $gate->enter($ctx->companyId);
        if (!hash_equals($release['fingerprint'], Adapter::inspect($release['root'])['fingerprint'])) {
            throw new Failure('core_changed', 'O núcleo foi alterado fora do gerenciador de versões.', 503);
        }
        if ($this->asset($ctx, $release)) {
            return;
        }
        (new Runtime($this->app))->run($ctx, $release);
    }
    private function api(string $resource, ?string $id, ?string $company): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            throw new Failure('method', 'Este recurso de API aceita somente leitura.', 405);
        }
        $h = $this->headers();
        $auth = $h['authorization'] ?? '';
        if (!preg_match('/\ABearer ([A-Za-z0-9_-]+)\z/', $auth, $m)) {
            throw new Failure('api_token', 'Informe o token Bearer.', 401);
        }
        $token = $this->app->auth->token($m[1], $resource.':read', $company);
        $company = $token['company_id'];
        $gate = new ReleaseGate($this->app);
        $release = $gate->enter($company);
        if (!hash_equals($release['fingerprint'], Adapter::inspect($release['root'])['fingerprint'])) {
            throw new Failure('core_changed', 'O núcleo foi alterado fora do gerenciador de versões.', 503);
        }
        if ($resource === 'context') {
            Response::json(['company_id' => $company,'organization_id' => $this->app->catalog->company($company)['organization_id'],'staff_id' => $token['staff_id']]);
        }
        if ($resource === 'events') {
            $rows = $this->app->db->all('SELECT id,provider_event,status,attempts,error_code,created_at FROM pme_events WHERE company_id=? ORDER BY created_at DESC LIMIT 100', [$company]);
            Response::json(['data' => $rows]);
        }
        $actor = ['user_id' => $token['user_id'],'staff_id' => $token['staff_id'],'mfa' => $token['mfa'] ?? 0];
        $ctx = (new Resolver($this->app))->forCompany($company, 'multiempresa/api/read/'.$resource.($id ? '/'.$id : ''), $actor);
        $GLOBALS['pme_api'] = true;
        (new Runtime($this->app))->run($ctx, $release);
    }
    private function asset(Context $ctx, array $release): bool
    {
        $route = $ctx->route;
        if($route==='__pme/branding'){
            if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){throw new Failure('method','Use GET ou HEAD.',405);}
            $filename=$_GET['file']??null;
            if(!is_string($filename)||$filename===''||strlen($filename)>255||preg_match('~[/\\\\\x00-\x1f\x7f]~',$filename)||$filename==='.'||$filename==='..'){
                throw new Failure('branding_file','Imagem empresarial inválida.',400);
            }
            $route='uploads/company/'.$filename;
        }
        $publicLogo = str_starts_with($route, 'uploads/company/');
        if (str_starts_with($route, 'uploads/') && !$publicLogo) {
            throw new Failure('private_attachment', 'Use o download autenticado do documento.', 403);
        }
        if ($publicLogo) {
            $file = Files::inside($this->app->state('companies/'.$ctx->companyId.'/uploads/company'), substr($route, 16));
        } elseif (str_starts_with($route, 'assets/')) {
            $file = Files::inside($release['root'].'/assets', substr($route, 7));
        } elseif (preg_match('~\Amodules/([a-z][a-z0-9_]+)/assets/(.+)\z~', $route, $m)) {
            $allowed = $this->app->db->one('SELECT name FROM pme_modules WHERE company_id=? AND name=? AND active=1', [$ctx->companyId,$m[1]]);
            if (!$allowed || $m[1] === 'multiempresa') {
                throw new Failure('module_asset', 'Módulo indisponível.', 404);
            }
            $file = Files::inside($release['root'].'/modules/'.$m[1].'/assets', $m[2]);
        } else {
            return false;
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $types = ['css' => 'text/css','js' => 'application/javascript','png' => 'image/png','jpg' => 'image/jpeg','jpeg' => 'image/jpeg','gif' => 'image/gif','webp' => 'image/webp','ico' => 'image/x-icon','svg' => 'image/svg+xml','woff' => 'font/woff','woff2' => 'font/woff2','ttf' => 'font/ttf'];
        if (!isset($types[$ext]) || ($publicLogo && !in_array($ext, ['png','jpg','jpeg','gif','webp','ico','svg'], true))) {
            throw new Failure('asset_extension', 'Tipo de arquivo indisponível.', 403);
        }
        // SVG is supported by native branding. A direct navigation must never run its scripts.
        if($publicLogo&&$ext==='svg'){header("Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'");}
        header('Content-Type: '.$types[$ext]);
        header('Cache-Control: '.($publicLogo ? 'private, no-cache' : 'public, max-age=3600'));
        header('Content-Length: '.filesize($file));
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD'){readfile($file);}
        return true;
    }
    private function secure(): bool
    {
        return (bool)($this->app->config['require_https'] ?? true);
    }
    private function nextRoute(mixed $route): string
    {
        if(!is_string($route)||strlen($route)>4096||!preg_match('~\Aadmin(?:/|\?|$)~',$route)||preg_match('/[\x00-\x20\x7f\\\\]/',$route)||preg_match('~(?:^|/)\.{1,2}(?:/|$)|%(?:2f|5c|00|25|2e)~i',explode('?',$route,2)[0])){return 'admin';}
        return $route;
    }
    private function headers(): array
    {
        $out = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $out[strtolower(str_replace('_', '-', substr($k, 5)))] = (string)$v;
            }
        }
        return $out;
    }
}
