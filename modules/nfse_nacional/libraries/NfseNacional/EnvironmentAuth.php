<?php
declare(strict_types=1);
namespace NfseNacional;

/** Authentication policy for the documented national endpoints (SEFIN/ADN).
 * Web portal login is NOT an authentication method for these API endpoints.
 * Never exchange stored portal credentials for an invented bearer token.
 */
final class EnvironmentAuth
{
    public const ENVIRONMENTS = ['1', '2'];
    public const METHODS = ['certificate', 'username_password'];
    public const SOURCES = ['automatic', 'company', 'environment'];

    public static function profile(array $settings, string $environment): array
    {
        self::assertEnvironment($environment);
        if (($settings['auth_unified'] ?? '0') === '1') {
            if (($settings['auth_certificate_conflict'] ?? '0') === '1') { throw new \RuntimeException('Existem certificados anteriores diferentes. Escolha o certificado compartilhado nas configurações de autenticação. Os cadastros anteriores foram preservados.'); }
            return ['method' => (string) ($settings['auth_method'] ?? 'certificate'), 'certificate_source' => 'automatic'];
        }
        $stored = $settings['auth'][$environment] ?? [];
        if (!is_array($stored)) { throw new \InvalidArgumentException('Configuração de autenticação inválida.'); }
        $method = (string) ($stored['method'] ?? 'certificate');
        $source = (string) ($stored['certificate_source'] ?? 'automatic');
        if (!in_array($method, self::METHODS, true) || !in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException('Configuração de autenticação inválida.');
        }
        return ['method' => $method, 'certificate_source' => $source];
    }

    public static function assertEnvironment(string $environment): void
    {
        if (!in_array($environment, self::ENVIRONMENTS, true)) {
            throw new \InvalidArgumentException('Ambiente fiscal inválido.');
        }
    }

    /** Current documented SEFIN/ADN endpoints use a client certificate (mTLS).
     * Do not allow credential-only transmission until the authority documents it.
     */
    public static function assertApiSupported(array $settings, string $environment): void
    {
        if (self::profile($settings, $environment)['method'] !== 'certificate') {
            throw new \RuntimeException('O login por usuário e senha existe no Portal Web NFS-e, mas não há fluxo de autenticação por senha documentado para os endpoints SEFIN/ADN utilizados pelo módulo. Selecione Certificado A1 para validar, consultar ou transmitir pela API. Nenhuma credencial foi enviada.');
        }
    }

    /** Only safe flags and public certificate metadata leave the persistence layer. */
    public static function publicState(array $material): array
    {
        $result = [];
        foreach (self::ENVIRONMENTS as $environment) {
            $row = is_array($material[$environment] ?? null) ? $material[$environment] : [];
            $login = is_array($material['shared_login'] ?? null) ? $material['shared_login'] : (is_array($row['login'] ?? null) ? $row['login'] : []);
            $certificate = is_array($row['certificate'] ?? null) ? $row['certificate'] : [];
            $result[$environment] = [
                'login_configured' => ($login['username'] ?? '') !== '' && ($login['password'] ?? '') !== '',
                'legacy_login_configured' => !empty($row['login']['username']) && !empty($row['login']['password']),
                'certificate_available' => !empty($certificate['pfx']) && array_key_exists('password', $certificate),
                'certificate_metadata' => is_array($certificate['metadata'] ?? null) ? $certificate['metadata'] : [],
                'password_api_supported' => false,
            ];
        }
        $profiles = $material['legacy_profiles'] ?? [];
        $result['legacy_profiles_differ'] = isset($profiles['1'], $profiles['2']) && $profiles['1'] !== $profiles['2'];
        return $result;
    }

    /** Pure preparation step; credentials are never inserted into settings_json.
     * $uploads contains byte arrays only after the controller validates multipart uploads.
     */
    public static function prepareMaterial(array $previous, array $input, array $uploads, string $issuer): array
    {
        $result = $previous;
        if (self::flag($input, 'auth_clear_login')) { unset($result['shared_login']); foreach (self::ENVIRONMENTS as $env) { unset($result[$env]['login']); } }
        $username = $input['auth_username'] ?? '';
        $password = $input['auth_password'] ?? '';
        if (!is_string($username) || !is_string($password) || strlen($username) > 200 || strlen($password) > 1024
            || strpbrk($username . $password, "\0\r\n") !== false) { throw new \InvalidArgumentException('Usuário ou senha inválidos.'); }
        $username = trim($username);
        if ($username !== '' || $password !== '') {
            if (self::flag($input, 'auth_clear_login')) { throw new \InvalidArgumentException('Não é possível excluir e cadastrar as credenciais simultaneamente.'); }
            $old = $result['shared_login'] ?? [];
            if ($username !== '' && $username !== ($old['username'] ?? '') && $password === '') { throw new \InvalidArgumentException('Informe a senha ao alterar o usuário.'); }
            $login = ['username' => $username !== '' ? $username : ($old['username'] ?? ''), 'password' => $password !== '' ? $password : ($old['password'] ?? '')];
            if ($login['username'] === '' || $login['password'] === '') { throw new \InvalidArgumentException('Informe usuário e senha.'); }
            $result['shared_login'] = $login;
            // Legacy environment credentials remain encrypted for recovery.
        }
        foreach (self::ENVIRONMENTS as $environment) {
            $row = is_array($result[$environment] ?? null) ? $result[$environment] : [];
            $removeLogin = self::flag($input, 'auth_clear_login_' . $environment);
            $removeCert = self::flag($input, 'auth_clear_certificate_' . $environment);
            if ($removeLogin) { unset($row['login']); }
            if ($removeCert) { unset($row['certificate']); }

            $usernameField = 'auth_username_' . $environment;
            $passwordField = 'auth_password_' . $environment;
            $username = $input[$usernameField] ?? '';
            $password = $input[$passwordField] ?? '';
            if (!is_string($username) || !is_string($password) || strlen($username) > 200 || strlen($password) > 1024
                || strpbrk($username . $password, "\0\r\n") !== false) {
                throw new \InvalidArgumentException('Credenciais inválidas para o ambiente selecionado.');
            }
            $username = trim($username);
            if ($removeLogin && ($username !== '' || $password !== '')) {
                throw new \InvalidArgumentException('Não é possível apagar e cadastrar as mesmas credenciais numa única operação.');
            }
            if ($username !== '' || $password !== '') {
                $old = is_array($row['login'] ?? null) ? $row['login'] : [];
                $finalUsername = $username !== '' ? $username : (string) ($old['username'] ?? '');
                $finalPassword = $password !== '' ? $password : (string) ($old['password'] ?? '');
                if ($username !== '' && $username !== ($old['username'] ?? '') && $password === '') {
                    throw new \InvalidArgumentException('Ao alterar o usuário, informe também a respectiva senha.');
                }
                if ($finalUsername === '' || $finalPassword === '') {
                    throw new \InvalidArgumentException('Informe usuário e senha para cadastrar as credenciais.');
                }
                $row['login'] = ['username' => $finalUsername, 'password' => $finalPassword];
            }
            if (isset($uploads[$environment])) {
                if ($removeCert) { throw new \InvalidArgumentException('Não é possível remover e substituir o mesmo certificado simultaneamente.'); }
                $file = $uploads[$environment];
                if (!is_array($file) || !is_string($file['bytes'] ?? null) || !is_string($file['password'] ?? null)) {
                    throw new \InvalidArgumentException('Arquivo A1 inválido.');
                }
                $certificate = Certificate::fromPkcs12($file['bytes'], $file['password'], $issuer);
                $row['certificate'] = [
                    'pfx' => base64_encode($file['bytes']),
                    'password' => $file['password'],
                    'metadata' => $certificate->metadata(),
                ];
            }
            if ($row !== []) { $result[$environment] = $row; }
            else { unset($result[$environment]); }
        }
        return $result;
    }

    private static function flag(array $input, string $key): bool
    {
        $value = $input[$key] ?? '0';
        if (!in_array($value, ['0', '1'], true)) { throw new \InvalidArgumentException('Comando de credenciais inválido.'); }
        return $value === '1';
    }
}
