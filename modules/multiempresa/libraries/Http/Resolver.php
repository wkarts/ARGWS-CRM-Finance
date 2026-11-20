<?php

declare(strict_types=1);

namespace Multiempresa\Http;

use Multiempresa\Application\App;
use Multiempresa\Domain\{Context,Failure};

final class Resolver
{
    public function __construct(private App $app)
    {
    }
    /** Host headers are exact allowlist lookups. Forwarded headers are intentionally ignored. */
    public function resolve(string $host, string $uri, ?array $principal = null): ?Context
    {
        $base = parse_url($this->app->config['base_url']);
        $central = strtolower($base['host'].(isset($base['port']) ? ':'.$base['port'] : ''));
        if (!preg_match('/\A[a-zA-Z0-9.-]+(?::[0-9]{1,5})?\z/', $host)) {
            throw new Failure('host', 'Host inválido.', 400);
        }
        $host = strtolower($host);
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || str_contains($path, '\\') || preg_match('/%(?:2f|5c|00|25|2e)/i', $path) || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)) {
            throw new Failure('uri', 'URL inválida.', 400);
        }
        $path = rawurldecode($path);
        // A short route uses the native root, while old links keep their transport mount.
        $rootMount=rtrim(parse_url($this->app->config['native_url'],PHP_URL_PATH)??'','/');
        $shortPath=$host===$central && preg_match('~\A'.preg_quote($rootMount,'~').'/[a-f0-9]{20}(?:/|$)~',$path);
        $mount = $shortPath?$rootMount:rtrim($base['path'] ?? '', '/');
        if ($host === $central && $mount !== '') {
            if ($path !== $mount && !str_starts_with($path,$mount.'/')) { throw new Failure('entry_path','Entrada do módulo inválida.',404); }
            $path=substr($path,strlen($mount)) ?: '/';
        }
        $domain = $this->app->db->one('SELECT company_id FROM pme_hosts WHERE host=? AND verified=1', [$host]);
        $orgDomain=$this->app->db->one('SELECT organization_id FROM pme_organization_hosts WHERE host=? AND verified=1',[$host]);
        if ($host !== $central && !$domain && !$orgDomain) {
            throw new Failure('unknown_host', 'Domínio não cadastrado.', 421);
        }
        $company = null;
        $route = ltrim($path, '/');
        $baseUrl = '';
        if(($host===$central||$orgDomain) && preg_match('~\A/([a-f0-9]{20})(?:/(.*))?\z~',$path,$m)){
            $routes=new \Multiempresa\Application\CompanyRoutes($this->app);$id=$routes->resolve($m[1]);
            if(!$id||$id===\Multiempresa\Application\Installation::ID){throw new Failure('company_not_found','Empresa não encontrada.',404);}
            $company=$this->app->catalog->company($id);$route=$m[2]??'';$baseUrl=$orgDomain?'https://'.$host.'/'.$m[1].'/':$routes->url($id);
        }elseif($orgDomain && preg_match('~\A/e/([a-z0-9-]+)(?:/(.*))?\z~',$path,$m)){
            $row=$this->app->db->one('SELECT id FROM pme_companies WHERE organization_id=? AND slug=?',[$orgDomain['organization_id'],$m[1]])??throw new Failure('company_not_found','Empresa não encontrada nesta organização.',404);
            $company=$this->app->catalog->company($row['id']);$route=$m[2]??'';$baseUrl='https://'.$host.'/e/'.$m[1].'/';
        }elseif (preg_match('~\A/o/([a-z0-9-]+)/e/([a-z0-9-]+)(?:/(.*))?\z~', $path, $m)) {
            $company = $this->app->catalog->bySlug($m[1], $m[2]);
            $route = $m[3] ?? '';
            if ($domain && $domain['company_id'] !== $company['id']) {
                throw new Failure('context_mismatch', 'Domínio e rota pertencem a empresas diferentes.', 403);
            }
            if ($domain) {
                throw new Failure('canonical_route', 'Use a raiz do domínio empresarial.', 400);
            }
            $baseUrl = Entry::url($this->app,rtrim($this->app->config['base_url'], '/').'/o/'.$m[1].'/e/'.$m[2].'/');
        } elseif ($domain) {
            $company = $this->app->catalog->company($domain['company_id']);
            $baseUrl = 'https://'.$host.'/';
        } elseif (str_starts_with($path, '/o/')) {
            throw new Failure('route', 'Rota empresarial inválida.', 404);
        }
        if (!$company) {
            return null;
        }
        if($orgDomain&&$company['organization_id']!==$orgDomain['organization_id']){throw new Failure('organization_scope','Domínio e empresa pertencem a organizações diferentes.',403);}
        if (!(int)$company['organization_active']) {
            throw new Failure('organization_disabled', 'Organização suspensa.', 403);
        }
        $secrets = $this->app->vault->open($company['secrets']);
        return new Context($company['id'], $company['organization_id'], $company['mode'], $company['table_prefix'], $baseUrl, $route, $secrets['connection'], $secrets['encryption_key'], $principal);
    }
    public function forCompany(string $company, string $route = '', ?array $principal = null): Context
    {
        $c = $this->app->catalog->company($company);
        $s = $this->app->vault->open($c['secrets']);
        return new Context($c['id'], $c['organization_id'], $c['mode'], $c['table_prefix'], $this->app->catalog->url($c), $route, $s['connection'], $s['encryption_key'], $principal);
    }
}
