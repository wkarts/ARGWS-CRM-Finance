<?php
declare(strict_types=1);
namespace NfseNacional;

/** Non-destructive transition. Legacy encrypted values remain available for explicit selection. */
final class UnifiedAuth
{
    public static function plan(array $settings, array $material, ?array $local): array
    {
        if (($settings['auth_unified'] ?? '0') === '1') { return [$settings, $material, null]; }
        $material['legacy_profiles'] ??= $settings['auth'] ?? [];
        if ($local !== null) { $material['legacy_local_certificate'] ??= $local; }
        $candidates = self::certificates($material, $local);
        $unique = [];
        foreach ($candidates as $certificate) { $unique[hash('sha256', (string) $certificate['pfx'] . "\0" . (string) $certificate['password'])] = $certificate; }
        $settings['auth_method'] = $settings['auth'][$settings['environment'] ?? '2']['method'] ?? 'certificate';
        $settings['auth_unified'] = '1';
        $settings['auth_certificate_conflict'] = count($unique) > 1 ? '1' : '0';
        $shared = count($unique) === 1 ? array_values($unique)[0] : null;
        if (!isset($material['shared_login'])) {
            $logins = [];
            foreach (EnvironmentAuth::ENVIRONMENTS as $env) {
                if (isset($material[$env]['login'])) { $logins[hash('sha256', serialize($material[$env]['login']))] = $material[$env]['login']; }
            }
            if (count($logins) === 1) { $material['shared_login'] = array_values($logins)[0]; }
        }
        return [$settings, $material, $shared];
    }
    public static function certificates(array $material, ?array $local): array
    {
        $result = [];
        if (!empty($local['pfx'])) { $result['local'] = $local; }
        foreach (EnvironmentAuth::ENVIRONMENTS as $env) {
            if (!empty($material[$env]['certificate']['pfx'])) { $result[$env] = $material[$env]['certificate']; }
        }
        return $result;
    }
    public static function upgrade($model): void
    {
        $model->lock('auth-upgrade', function () use ($model) {
            $settings = $model->settings();
            if (($settings['auth_unified'] ?? '0') === '1') { return; }
            $row = $model->settingsRow();
            $local = empty($row['certificate_enc']) ? null : \Nfse_nacional_model::decode($model->crypto()->decrypt($row['certificate_enc']));
            if ($local !== null && !isset($local['metadata'])) { $local['metadata'] = \Nfse_nacional_model::decode($row['certificate_meta'] ?? '{}'); }
            [$settings, $material, $shared] = self::plan($settings, $model->authMaterial(), $local);
            $model->saveSettings($settings, $shared ? \Nfse_nacional_model::json($shared) : null, $shared['metadata'] ?? null, $material);
        });
    }
}
