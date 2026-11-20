<?php

declare(strict_types=1);

namespace Multiempresa\Perfex;

use Multiempresa\Contracts\MigrationAdapter;
use Multiempresa\Application\App;
use Multiempresa\Infrastructure\{Process,Files};
use Multiempresa\Domain\{Failure,Id};

final class Adapter implements MigrationAdapter
{
    public function __construct(private App $app)
    {
    }
    public static function inspect(string $root): array
    {
        $root = realpath($root) ?: throw new Failure('release_root', 'Diretório da versão não encontrado.');
        $required = ['application/config/config.php','application/config/constants.php','application/config/database.php','application/config/hooks.php','application/config/migration.php','application/core/App_Controller.php','system/core/CodeIgniter.php','application/hooks/InitHook.php'];
        $ctx = hash_init('sha256');
        foreach ($required as $file) {
            if (!is_file($root.'/'.$file)) {
                throw new Failure('release_file', 'Versão incompleta: '.$file);
            }
            hash_update($ctx, $file.':'.hash_file('sha256', $root.'/'.$file));
        }
        $config = file_get_contents($root.'/application/config/migration.php');
        if (!preg_match('/migration_version[\x27"]\]\s*=\s*(\d+)/', $config, $m)) {
            throw new Failure('release_version', 'Versão do esquema não identificada.');
        }
        if (!str_contains(file_get_contents($root.'/application/config/config.php'), 'APP_DB_PREFIX')) {
            throw new Failure('release_prefix', 'Núcleo sem suporte ao prefixo dinâmico.');
        }
        return ['root' => $root,'version' => (int)$m[1],'fingerprint' => hash_final($ctx)];
    }
    public function register(string $root): string
    {
        $r = self::inspect($root);
        $id = Id::new();
        $this->app->db->exec('INSERT INTO pme_releases(id,root,version,fingerprint,created_at) VALUES(?,?,?,?,?)', [$id,$r['root'],$r['version'],$r['fingerprint'],time()]);
        return $id;
    }
    public function preflight(array $company, string $releaseRoot): void
    {
        $target = self::inspect($releaseRoot);
        if ($target['version'] < (int)$company['schema_version']) {
            throw new Failure('downgrade', 'Downgrade automático de esquema é proibido.');
        }
        $release = $this->app->db->one('SELECT id FROM pme_releases WHERE root=? ORDER BY created_at DESC', [$releaseRoot]);
        foreach ($this->app->db->all('SELECT name,fingerprint FROM pme_modules WHERE company_id=? AND active=1', [$company['id']]) as $module) {
            $path = $releaseRoot.'/modules/'.$module['name'];
            $review = $release ? $this->app->db->one('SELECT fingerprint FROM pme_module_reviews WHERE release_id=? AND company_id=? AND name=?', [$release['id'],$company['id'],$module['name']]) : null;
            $expected = $review['fingerprint'] ?? $module['fingerprint'];
            if (!is_dir($path) || !hash_equals($expected, Files::treeHash($path))) {
                throw new Failure('module_review', 'A nova versão do módulo exige homologação: '.$module['name']);
            }
        }
    }
    public function migrate(array $company, string $releaseRoot): void
    {
        $release = $this->app->db->one('SELECT * FROM pme_releases WHERE root=? ORDER BY created_at DESC', [$releaseRoot]) ?? throw new Failure('release', 'Versão não registrada.');
        Process::run([$this->app->config['php_binary'] ?? PHP_BINARY,dirname(__DIR__, 2).'/scripts/tenant-worker.php'], ['company_id' => $company['id'],'operation' => 'migrate','release' => $release]);
        // Perfex migration class names are global. Each module needs its own PHP process.
        foreach ($this->app->db->all('SELECT name FROM pme_modules WHERE company_id=? AND active=1',[$company['id']]) as $module) {
            Process::run([$this->app->config['php_binary'] ?? PHP_BINARY,dirname(__DIR__,2).'/scripts/tenant-worker.php'],['company_id'=>$company['id'],'operation'=>'module_migrate','module'=>$module['name'],'release'=>$release]);
        }

    }
    public function verify(array $company, string $releaseRoot): void
    {
        $secrets = $this->app->vault->open($company['secrets']);
        $pdo = $this->app->storage($company['mode'])->connect($secrets['connection']);
        $owner = $pdo->query('SELECT company_id FROM `'.$company['table_prefix'].'pme_owner` WHERE id=1')->fetchColumn();
        if ($owner !== $company['id']) {
            throw new Failure('storage_owner', 'O banco restaurado não pertence a esta empresa.');
        }
        $version = (int)$pdo->query('SELECT version FROM `'.$company['table_prefix'].'migrations`')->fetchColumn();
        if ($version !== self::inspect($releaseRoot)['version']) {
            throw new Failure('migration_version', 'O esquema não atingiu a versão esperada.');
        }
        foreach (['staff','clients','invoices','options','modules','sessions'] as $table) {
            $pdo->query('SELECT 1 FROM `'.$company['table_prefix'].$table.'` LIMIT 1');
        }
    }
}
