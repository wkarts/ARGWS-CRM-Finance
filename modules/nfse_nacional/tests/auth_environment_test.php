<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
require dirname(__DIR__) . '/autoload.php';
if (!function_exists('mb_strlen')) { function mb_strlen(string $value, string $charset = 'UTF-8'): int { return iconv_strlen($value, $charset); } }
if (!function_exists('mb_strtolower')) { function mb_strtolower(string $value, string $charset = 'UTF-8'): string { return strtolower($value); } }

use NfseNacional\EnvironmentAuth;
use NfseNacional\Config;
use NfseNacional\Crypto;
use NfseNacional\Certificate;
use NfseNacional\CertificateResolver;

$checks = 0;
function authCheck(bool $condition, string $message): void { global $checks; if (!$condition) { throw new RuntimeException('FALHOU: ' . $message); } ++$checks; }
function authReject(callable $callback, string $message, string $expected = ''): void {
    try { $callback(); } catch (Throwable $error) {
        authCheck($expected === '' || str_contains($error->getMessage(), $expected), $message); return;
    }
    throw new RuntimeException('FALHOU: ' . $message);
}
$base = Config::defaults();
authCheck(EnvironmentAuth::profile($base, '1')['method'] === 'certificate', 'Produção inicia com certificado');
authCheck(EnvironmentAuth::profile($base, '2')['method'] === 'certificate', 'Homologação inicia com certificado');
authCheck(EnvironmentAuth::profile($base, '1')['certificate_source'] === 'automatic', 'Reaproveita A1 de cadastro existente por padrão');
authReject(fn() => EnvironmentAuth::profile($base, '3'), 'Ambiente não cadastrado bloqueado');
$changed = Config::sanitize(['auth' => ['1' => ['method' => 'username_password'], '2' => ['certificate_source' => 'environment']]], $base);
authCheck($changed['auth']['1']['method'] === 'username_password' && $changed['auth']['2']['method'] === 'certificate', 'Métodos configurados independentemente');
authCheck($changed['auth']['2']['certificate_source'] === 'environment', 'A1 da homologação escolhido independentemente');
authReject(fn() => EnvironmentAuth::assertApiSupported($changed, '1'), 'Usuário e senha não usados como autenticação fictícia', 'Portal Web');
EnvironmentAuth::assertApiSupported($changed, '2');
authCheck(true, 'Certificado permitido em homologação mesmo com senha cadastrada na produção');
authReject(fn() => Config::sanitize(['auth'=>['2'=>['method'=>'bearer']]], $base), 'Token inventado não é método aceitável');
authReject(fn() => Config::sanitize(['auth'=>['1'=>['certificate_source'=>'../etc']]], $base), 'Caminhos arbitrários não são certificados');
authReject(fn() => Config::sanitize(['auth'=>['3'=>['method'=>'certificate']]], $base), 'Ambiente arbitrário não permitido');

$tax = '11222333000181';
$config = tempnam(sys_get_temp_dir(), 'nfse_auth_offline_');
file_put_contents($config, "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=OFFLINE\n");
try {
    $key = openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA,'config'=>$config]);
    $csr = openssl_csr_new(['commonName'=>'TESTE AMBIENTE:'.$tax],$key,['config'=>$config,'digest_alg'=>'sha256']);
    $cert = openssl_csr_sign($csr,null,$key,2,['config'=>$config,'digest_alg'=>'sha256']);
    $pfxOne = ''; $pfxTwo = '';
    authCheck(openssl_pkcs12_export($cert,$pfxOne,$key,'strong-env-1'), 'A1 sintético de produção gerado');
    authCheck(openssl_pkcs12_export($cert,$pfxTwo,$key,'strong-env-2'), 'A1 sintético de homologação gerado');
} finally { unlink($config); }
$uploads = ['1'=>['bytes'=>$pfxOne,'password'=>'strong-env-1'], '2'=>['bytes'=>$pfxTwo,'password'=>'strong-env-2']];
$input = ['auth_username_1'=>'responsavel@exemplo.invalid', 'auth_password_1'=>'senha-privada-prd',
    'auth_username_2'=>'homologacao', 'auth_password_2'=>'senha-privada-hml'];
$material = EnvironmentAuth::prepareMaterial([], $input, $uploads, $tax);
$summary = EnvironmentAuth::publicState($material);
authCheck($summary['1']['login_configured'] && $summary['2']['login_configured'], 'Duas credenciais salvas em separado');
authCheck($summary['1']['certificate_available'] && $summary['2']['certificate_available'], 'Dois certificados A1 isolados');
authCheck(!$summary['1']['password_api_supported'] && !$summary['2']['password_api_supported'], 'Não promete API de senha sem documentação');
authCheck(!str_contains(json_encode($summary), 'senha-privada') && !str_contains(json_encode($summary), base64_encode($pfxOne)), 'Visão pública não revela senhas ou bytes');
authCheck(Certificate::fromPkcs12(base64_decode($material['1']['certificate']['pfx']), 'strong-env-1', $tax)->metadata()['tax_id'] === $tax, 'A1 de produção válido');
authReject(fn() => Certificate::fromPkcs12(base64_decode($material['1']['certificate']['pfx']), 'strong-env-2', $tax), 'Senha de outro ambiente não abre A1 de produção');
authReject(fn() => EnvironmentAuth::prepareMaterial($material, [], ['1'=>['bytes'=>$pfxOne,'password'=>'errada']], $tax), 'Upload A1 com senha errada recusa substituição');
authReject(fn() => EnvironmentAuth::prepareMaterial($material, ['auth_username_1'=>'outro'], [], $tax), 'Alterar usuário exige renovar senha');
$updated = EnvironmentAuth::prepareMaterial($material, ['auth_password_1'=>'senha-nova'], [], $tax);
authCheck($updated['1']['login']['password'] === 'senha-nova' && $updated['2']['login']['password'] === 'senha-privada-hml', 'Troca de senha altera apenas produção');
$updated = EnvironmentAuth::prepareMaterial($material, ['auth_clear_login_1'=>'1'], [], $tax);
authCheck(!isset($updated['1']['login']) && isset($updated['2']['login']), 'Exclusão explícita de login não altera outra UF/ambiente');
$updated = EnvironmentAuth::prepareMaterial($material, ['auth_clear_certificate_2'=>'1'], [], $tax);
authCheck(!isset($updated['2']['certificate']) && isset($updated['1']['certificate']), 'Exclusão de A1 por ambiente não remove outra instalação');
$secret = str_repeat('encryption-test-', 4);
$crypto = new Crypto($secret, 'nfse_nacional:company-A:prefix-a_');
$encrypted = $crypto->encrypt(json_encode($material, JSON_THROW_ON_ERROR));
authCheck(!str_contains($encrypted, 'senha-privada') && !str_contains($encrypted, base64_encode($pfxOne)), 'Material criptografado não revela login ou A1');
authCheck(json_decode($crypto->decrypt($encrypted), true)['1']['login']['username'] === 'responsavel@exemplo.invalid', 'Material cifrado recuperado no próprio contexto');
authReject(fn() => (new Crypto($secret, 'nfse_nacional:company-B:prefix-b_'))->decrypt($encrypted), 'Outro contexto empresarial não descriptografa');

function db_prefix(): string { return 'env_auth_test_'; }
function is_staff_logged_in(): bool { return true; }
function get_staff_user_id(): int { return 7; }
function get_option(string $name): string { return $name === 'company_vat' ? '11.222.333/0001-81' : ''; }
function nfse_nacional_scope(): string { return hash('sha256', 'env-auth-offline:company-A'); }
final class AuthEnvHooks {
    public int $calls = 0;
    public function apply_filters(string $name, $value, array $context) { ++$this->calls; return null; }
}
$authHooks = new AuthEnvHooks();
function hooks(): AuthEnvHooks { return $GLOBALS['authHooks']; }
class AuthModelFixture {
    public array $configuration;
    public array $material;
    public function __construct(array $config, array $material) { $this->configuration=$config; $this->material=$material; }
    public function settings(): array { return $this->configuration; }
    public function settingsRow(): array { return ['certificate_enc' => null]; }
    public function authMaterial(): array { return $this->material; }
}
$cfg = Config::defaults(); $cfg['provider']['tax_id']=$tax;
$cfg['auth']['1']['certificate_source']='environment';
$cfg['auth']['2']['certificate_source']='environment';
$model = new AuthModelFixture($cfg,$material);
$resolver = new CertificateResolver($model);
authCheck($resolver->resolve($tax,'1')->metadata()['tax_id']===$tax, 'A1 separado resolvido em produção');
authCheck($resolver->resolve($tax,'2')->metadata()['tax_id']===$tax, 'A1 separado resolvido em homologação');
$model->configuration['auth']['1']['method']='username_password';
$previousCalls = $authHooks->calls;
authReject(fn() => $resolver->resolve($tax, '1'), 'Senha configurada bloqueia API sem certificado silencioso', 'Portal Web');
authCheck($authHooks->calls===$previousCalls, 'Seleção de senha não toca no provedor nem transmite');
authCheck($resolver->resolve($tax, '2')->metadata()['tax_id']===$tax, 'Homologação com A1 permanece disponível');
$model->configuration['auth']['1']['method']='certificate';
$model->material['1']['certificate']=$material['2']['certificate'];
$model->material['1']['certificate']['password']='strong-env-1';
authReject(fn()=>$resolver->resolve($tax,'1'), 'Senha trocada entre ambientes não autentica');
$model->material=$material;
$model->configuration['auth']['1']['certificate_source']='company';
authReject(fn()=>$resolver->resolve($tax,'1'), 'Modo somente empresa não usa certificado local do ambiente');
$model->configuration['auth']['1']['certificate_source']='environment';
authCheck($resolver->inspect($tax,'1')['source_label']==='A1 deste ambiente', 'Diagnóstico indica corretamente a origem');
echo 'OK: '.$checks." verificações de autenticação por ambiente, isolamento e proteção offline.\n";
