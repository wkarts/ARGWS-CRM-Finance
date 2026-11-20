<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'NfseNacional\\')) { require_once dirname(__DIR__) . '/libraries/NfseNacional/' . substr($class, 13) . '.php'; }
});

use NfseNacional\Certificate;
use NfseNacional\CertificateResolver;
use NfseNacional\CompanyContext;
use NfseNacional\Config;
use NfseNacional\Crypto;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FALHOU: ' . $label); } ++$checks; echo 'OK: ' . $label . PHP_EOL; }
function refuses(callable $operation, string $label, ?callable $predicate = null): void {
    try { $operation(); } catch (Throwable $error) { check($predicate === null || $predicate($error), $label); return; }
    throw new RuntimeException('FALHOU: ' . $label);
}
$testPrefix = 'native_'; $testStaff = 7; $testLoggedIn = true; $companyVat = '';
function db_prefix(): string { global $testPrefix; return $testPrefix; }
function get_staff_user_id(): int { global $testStaff; return $testStaff; }
function is_staff_logged_in(): bool { global $testLoggedIn; return $testLoggedIn; }
function get_option(string $name) { global $companyVat; return $name === 'company_vat' ? $companyVat : ''; }
function nfse_nacional_scope(): string { return hash('sha256', 'offline-database:' . db_prefix() . ':' . ($GLOBALS['pme_context']->companyId ?? 'standalone')); }
final class ResolverHooks {
    public $provider = null;
    public int $calls = 0;
    public function apply_filters(string $name, $value, array $context) {
        if ($name !== 'nfse_nacional_company_certificate' || $value !== null) { throw new RuntimeException('Contrato do hook incorreto.'); }
        ++$this->calls;
        return $this->provider ? ($this->provider)($context) : null;
    }
}
$resolverHooks = new ResolverHooks();
function hooks(): ResolverHooks { global $resolverHooks; return $resolverHooks; }

final class ResolverMemoryModel {
    public array $config;
    public array $row = ['certificate_enc' => null];
    private Crypto $cipher;
    public function __construct(string $taxId) {
        $this->config = Config::defaults(); $this->config['provider']['tax_id'] = $taxId;
        $this->cipher = new Crypto(str_repeat('resolver-test-secret-', 3), nfse_nacional_scope());
    }
    public function settings(): array { return $this->config; }
    public function settingsRow(): array { return $this->row; }
    public function crypto(): Crypto { return $this->cipher; }
    public function local(string $bytes, string $password): void {
        $this->row['certificate_enc'] = $this->cipher->encrypt(json_encode(['pfx' => base64_encode($bytes), 'password' => $password], JSON_THROW_ON_ERROR));
    }
}

function syntheticA1(string $taxId, string $label): string {
    $config = tempnam(sys_get_temp_dir(), 'nfse_resolver_');
    file_put_contents($config, "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=OFFLINE\n");
    try {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $config]);
        $csr = openssl_csr_new(['commonName' => $label . ':' . $taxId], $key, ['config' => $config, 'digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 2, ['config' => $config, 'digest_alg' => 'sha256']);
        $pfx = ''; if (!openssl_pkcs12_export($cert, $pfx, $key, 'offline-only-password')) { throw new RuntimeException('Falha no A1 sintético.'); }
        return $pfx;
    } finally { unlink($config); }
}
function providerPayload(array $context, string $pfx): array {
    return array_intersect_key($context, array_flip(['company_id', 'scope', 'tax_id'])) + ['pkcs12' => $pfx, 'password' => 'offline-only-password'];
}

$taxId = '11222333000181';
$localPfx = syntheticA1($taxId, 'LOCAL OFFLINE');
$companyPfx = syntheticA1($taxId, 'COMPANY OFFLINE');
$foreignPfx = syntheticA1('11444777000161', 'FOREIGN OFFLINE');
$localFingerprint = Certificate::fromPkcs12($localPfx, 'offline-only-password', $taxId)->metadata()['fingerprint_sha256'];
$companyFingerprint = Certificate::fromPkcs12($companyPfx, 'offline-only-password', $taxId)->metadata()['fingerprint_sha256'];
check(Config::defaults()['use_local_certificate'] === '0', 'Nova instalação começa com resolução automática');
refuses(fn() => Config::sanitize(['use_local_certificate' => 'on']), 'Prioridade local aceita somente a flag 0 ou 1');
$model = new ResolverMemoryModel($taxId);
$resolver = new CertificateResolver($model);
check(!$resolver->inspect()['available'], 'Ausência de fonte da empresa e de A1 local é informada sem inventar integração nativa');
$model->local($localPfx, 'offline-only-password');
check($resolver->resolve()->metadata()['fingerprint_sha256'] === $localFingerprint, 'A1 local é fallback quando as fontes automáticas estão ausentes');
$resolverHooks->provider = fn($context) => providerPayload($context, $companyPfx);
check($resolver->resolve()->metadata()['fingerprint_sha256'] === $companyFingerprint, 'Contrato da empresa tem prioridade no modo automático');
$model->config['use_local_certificate'] = '1'; $resolverHooks->calls = 0;
check($resolver->resolve()->metadata()['fingerprint_sha256'] === $localFingerprint && $resolverHooks->calls === 0, 'A1 local habilitado e configurado tem prioridade sem consultar outra fonte');
$emptyLocal = new ResolverMemoryModel($taxId); $emptyLocal->config['use_local_certificate'] = '1';
check((new CertificateResolver($emptyLocal))->resolve()->metadata()['fingerprint_sha256'] === $companyFingerprint, 'Prioridade local sem arquivo permite a resolução automática');
$model->config['use_local_certificate'] = '0';
foreach (['company_id' => 'foreign-company', 'scope' => str_repeat('0', 64), 'tax_id' => '11444777000161'] as $field => $wrong) {
    $resolverHooks->provider = static fn($context) => array_replace(providerPayload($context, $companyPfx), [$field => $wrong]);
    refuses(fn() => $resolver->resolve(), 'Contrato com ' . $field . ' de outro contexto não usa o fallback local');
}
$resolverHooks->provider = fn($context) => providerPayload($context, $foreignPfx);
refuses(fn() => $resolver->resolve(), 'Titular real do certificado deve corresponder ao emitente declarado');
$resolverHooks->provider = static function () { throw new RuntimeException('private/path password=DO_NOT_DISCLOSE'); };
$denied = $resolver->inspect();
check(!$denied['available'] && !str_contains($denied['message'], 'DO_NOT_DISCLOSE') && !str_contains($denied['message'], 'private/path'), 'Negação da integração interrompe o fallback e não expõe detalhes internos');
$resolverHooks->provider = fn($context) => providerPayload($context, $companyPfx);
$model->config['use_local_certificate'] = '1'; $model->local($localPfx, 'wrong-password'); $resolverHooks->calls = 0;
refuses(fn() => $resolver->resolve(), 'A1 local prioritário inválido não é substituído silenciosamente');
check($resolverHooks->calls === 0, 'Falha no A1 prioritário não acessa outra fonte');
$model->local($localPfx, 'offline-only-password');
$companyVat = '11.444.777/0001-61';
refuses(fn() => $resolver->resolve(), 'Cadastro atual da empresa impede usar outro CNPJ mesmo com A1 local prioritário');
$companyVat = '11.222.333/0001-81';
check($resolver->resolve()->metadata()['fingerprint_sha256'] === $localFingerprint, 'CPF/CNPJ cadastral formatado é normalizado antes da comparação');
$testLoggedIn = false;
refuses(fn() => $resolver->resolve(), 'Sem sessão de funcionário não há resolução de certificado');
$testLoggedIn = true;
$_POST = ['company_id' => 'other-company', 'scope' => str_repeat('0', 64)];
check(CompanyContext::current()->descriptor($taxId)['company_id'] === 'standalone', 'Identificador enviado no formulário não altera a empresa resolvida');
$_POST = [];
$status = $resolver->inspect();
$serialized = json_encode($status, JSON_THROW_ON_ERROR);
check($status['available'] && !str_contains($serialized, 'offline-only-password') && !str_contains($serialized, 'pkcs12') && !str_contains($serialized, base64_encode($localPfx)), 'Diagnóstico contém apenas metadados públicos e situação');
$nativeChecks = $checks;

// Exercise the actual supplied Multiempresa implementation using an isolated SQLite
// database. No production database, credentials or authenticated session are accessed.
$multiempresaRoot = getenv('NFSE_MULTIEMPRESA_ROOT') ?: '';
$autoloads = $multiempresaRoot !== '' ? [rtrim($multiempresaRoot, '/') . '/autoload.php']
    : [dirname(__DIR__, 2) . '/multiempresa/autoload.php', dirname(__DIR__, 2) . '/references/multiempresa/multiempresa/autoload.php'];
$autoload = null;
foreach ($autoloads as $candidate) { if (is_file($candidate)) { $autoload = $candidate; break; } }
if ($autoload === null || !class_exists(PDO::class) || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo PHP_EOL . $checks . " verificações de resolução aprovadas. Ponte Multiempresa não executada: forneça NFSE_MULTIEMPRESA_ROOT e PDO SQLite.\n";
    exit(0);
}
require_once $autoload;
$state = sys_get_temp_dir() . '/nfse-certificate-resolver-' . bin2hex(random_bytes(8));
mkdir($state, 0700);
try {
    $app = new \Multiempresa\Application\App(['central' => ['dsn' => 'sqlite::memory:', 'table_prefix' => 'core_multiempresa_'],
        'native_prefix' => 'core_', 'master_key' => base64_encode(random_bytes(32)), 'state_dir' => $state,
        'base_url' => 'https://crm.example.invalid/modules/multiempresa/tenant.php', 'native_url' => 'https://crm.example.invalid/']);
    \Multiempresa\Infrastructure\Schema::install($app->db);
    $app->db->pdo->exec('CREATE TABLE core_staff(staffid INTEGER PRIMARY KEY,email TEXT,firstname TEXT,lastname TEXT,password TEXT,active INTEGER,admin INTEGER,two_factor_auth_enabled INTEGER)');
    $app->db->pdo->exec("INSERT INTO core_staff VALUES(1,'manager@example.invalid','Offline','Manager','test-password-hash',1,0,0)");
    $app->db->pdo->exec('CREATE TABLE core_options(name TEXT,value TEXT)');
    $app->db->pdo->exec("INSERT INTO core_options VALUES('companyname','Offline installation')");
    $actor = $app->auth->syncNative(1);
    $org = str_repeat('a', 32); $company = str_repeat('b', 32); $otherCompany = str_repeat('c', 32);
    $app->db->exec('INSERT INTO pme_organizations(id,slug,name) VALUES(?,?,?)', [$org, 'test-org', 'Offline organization']);
    $app->db->exec('INSERT INTO pme_settings(name,value) VALUES(?,?)', ['installation_organization', $app->vault->seal(['organization_id' => $org])]);
    $app->db->exec("INSERT INTO pme_org_members(organization_id,user_id,role) VALUES(?,?,'admin')", [$org, $actor['id']]);
    foreach ([$company => 'tenant_a_', $otherCompany => 'tenant_b_'] as $companyId => $prefix) {
        $app->db->exec("INSERT INTO pme_companies(id,organization_id,slug,name,mode,table_prefix,storage_identity,secrets,status,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)",
            [$companyId, $org, $prefix, 'Offline ' . $prefix, 'prefix', $prefix, hash('sha256', $prefix), $app->vault->seal(['test' => true]), 'active', time()]);
    }
    $app->db->exec('INSERT INTO pme_memberships(company_id,user_id,staff_id) VALUES(?,?,?)', [$company, $actor['id'], 7]);
    $certificates = new \Multiempresa\Application\Certificates($app);
    $certificates->import($actor, $company, 'company.pfx', $companyPfx, 'offline-only-password');
    $certificates->import($actor, $otherCompany, 'other.pfx', $localPfx, 'offline-only-password');
    $principal = ['user_id' => $actor['id'], 'staff_id' => 7, 'company_id' => $company, 'auth_version' => (int) $actor['auth_version'], 'mfa' => 0];
    $context = new \Multiempresa\Domain\Context($company, $org, 'prefix', 'tenant_a_', 'https://crm.example.invalid/a/', 'admin/nfse_nacional', [], str_repeat('k', 32), $principal);
    $GLOBALS['pme_context'] = $context; $GLOBALS['pme_app'] = $app;
    define('PME_CONTEXT_READY', true);
    $testPrefix = 'tenant_a_'; $resolverHooks->provider = null;
    $tenantModel = new ResolverMemoryModel($taxId); $tenantModel->local($localPfx, 'offline-only-password');
    $tenantResolver = new CertificateResolver($tenantModel);
    check($tenantResolver->resolve()->metadata()['fingerprint_sha256'] === $companyFingerprint, 'API real Multiempresa fornece automaticamente o A1 da empresa atual');
    $accesses = $app->db->all("SELECT company_id FROM pme_audit WHERE action='certificate.accessed'");
    check(count($accesses) === 1 && $accesses[0]['company_id'] === $company, 'Leitura usa somente empresa atual e gera auditoria pelo contrato original');
    $app->db->exec('DELETE FROM pme_org_members WHERE user_id=?', [$actor['id']]);
    refuses(fn() => $tenantResolver->resolve(), 'Emissor delegado sem administração da organização não obtém certificado central nem fallback silencioso', fn($e) => $e->getCode() === 403);
    $tenantModel->config['use_local_certificate'] = '1';
    check($tenantResolver->resolve()->metadata()['fingerprint_sha256'] === $localFingerprint, 'Emissor autorizado na empresa pode usar seu A1 local explicitamente priorizado');
    $app->db->exec('UPDATE pme_memberships SET active=0 WHERE company_id=? AND user_id=?', [$company, $actor['id']]);
    refuses(fn() => $tenantResolver->resolve(), 'Vínculo operacional revogado também bloqueia A1 local prioritário');
    $app->db->exec('UPDATE pme_memberships SET active=1 WHERE company_id=? AND user_id=?', [$company, $actor['id']]);
    $app->db->exec("INSERT INTO pme_org_members(organization_id,user_id,role) VALUES(?,?,'admin')", [$org, $actor['id']]);
    $tenantModel->config['use_local_certificate'] = '0';
    $certificates->remove($actor, $company);
    check($tenantResolver->resolve()->metadata()['fingerprint_sha256'] === $localFingerprint, 'Ausência confirmada de A1 central permite fallback local');
    $tenantModel->row['certificate_enc'] = null;
    refuses(fn() => $tenantResolver->resolve(), 'A1 de outra empresa não é procurado mesmo quando possui o mesmo CNPJ');
    check(count($app->db->all("SELECT company_id FROM pme_audit WHERE action='certificate.accessed' AND company_id=?", [$otherCompany])) === 0, 'Nenhuma credencial da outra empresa foi acessada');
    $certificates->import($actor, $company, 'company.pfx', $companyPfx, 'offline-only-password');
    $foreignSecret = $app->db->one('SELECT secret FROM pme_certificates WHERE company_id=?', [$otherCompany])['secret'];
    $app->db->exec('UPDATE pme_certificates SET secret=? WHERE company_id=?', [$foreignSecret, $company]);
    $tenantModel->local($localPfx, 'offline-only-password');
    refuses(fn() => $tenantResolver->resolve(), 'Proteção de escopo do cofre original é preservada, sem fallback em caso de divergência');
    $testPrefix = 'tenant_b_';
    refuses(fn() => CompanyContext::current(), 'Prefixo do banco deve coincidir com o contexto imutável');
    $testPrefix = 'tenant_a_';
    $GLOBALS['pme_context'] = new \Multiempresa\Domain\Context($company, $org, 'prefix', 'tenant_a_', 'https://crm.example.invalid/a/', '', [], str_repeat('k', 32), null);
    refuses(fn() => CompanyContext::current(), 'Contexto sem principal autenticado não pode buscar A1');
    $GLOBALS['pme_context'] = $context;
    $tenantModel->config['use_local_certificate'] = '1';
    $app->db->pdo->exec("UPDATE core_staff SET password='changed-password-hash' WHERE staffid=1");
    refuses(fn() => $tenantResolver->resolve(), 'Alteração da identidade invalida sessão anterior antes de acessar qualquer A1');
} finally {
    foreach (glob($state . '/locks/*') ?: [] as $lock) { unlink($lock); }
    if (is_dir($state . '/locks')) { rmdir($state . '/locks'); }
    rmdir($state);
}
echo PHP_EOL . $checks . ' verificações aprovadas (' . $nativeChecks . ' de resolução e ' . ($checks - $nativeChecks) . " com código real Multiempresa/SQLite). Certificados sintéticos; nenhum banco ou certificado de produção.\n";
