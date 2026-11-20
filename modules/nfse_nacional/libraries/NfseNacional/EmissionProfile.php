<?php
declare(strict_types=1);
namespace NfseNacional;

/** Sparse fiscal preferences. Missing keys inherit; empty optional values and zero are explicit. */
final class EmissionProfile
{
    private const LEGACY_CLIENT = [
        'service_code' => 'national_code', 'municipal_service_code' => 'municipal_code',
        'nbs' => 'nbs', 'description' => 'description',
    ];
    private const LEGACY_RECIPIENT = ['service_code' => 'national_code', 'description' => 'description'];
    private const ENUMS = [
        'tax.iss_taxation' => ['1', '4'], 'tax.retention' => ['1', '2', '3'],
        'tax.total_tax_mode' => ['none', 'amount', 'simple', 'ibpt'],
        'ibscbs.enabled' => ['0', '1'], 'ibscbs.destination_indicator' => ['0'],
        'ibscbs.final_consumer' => ['', '0', '1'], 'ibscbs.purpose' => ['0'],
    ];
    private const DIGITS = [
        'service.municipality_code' => 7, 'service.municipal_code' => 3, 'service.nbs' => 9,
        'ibscbs.operation_indicator' => 6, 'ibscbs.cst' => 3, 'ibscbs.class_code' => 6,
    ];

    /** Only operational fields already supported by the DPS builder can be overridden. */
    public static function fields(): array
    {
        return array_intersect_key(Config::defaults(), array_flip(['service', 'tax', 'ibscbs']));
    }

    public static function sanitize(array $input): array
    {
        $allowed = self::fields(); $result = [];
        foreach ($input as $group => $values) {
            if (!is_string($group) || !isset($allowed[$group]) || !is_array($values)) {
                throw new \InvalidArgumentException('Grupo inválido no perfil de emissão.');
            }
            foreach ($values as $key => $value) {
                if (!is_string($key) || !array_key_exists($key, $allowed[$group])) {
                    throw new \InvalidArgumentException('Campo não permitido no perfil de emissão.');
                }
                $result[$group][$key] = self::value($group, $key, $value);
            }
        }
        return $result;
    }

    private static function value(string $group, string $key, $raw): string
    {
        $value = Config::text($raw, $key === 'description' ? 2000 : 200);
        $path = $group . '.' . $key;
        if ($path === 'service.national_code') {
            if ($value === '') { throw new \InvalidArgumentException('Selecione o serviço do perfil ou marque Usar padrão.'); }
            return Config::serviceCode($value);
        }
        if (isset(self::ENUMS[$path]) && !in_array($value, self::ENUMS[$path], true)) {
            throw new \InvalidArgumentException('Opção inválida no perfil de emissão: ' . $key . '.');
        }
        if (isset(self::DIGITS[$path]) && $value !== '') {
            if (!preg_match('/\A[0-9]{' . self::DIGITS[$path] . '}\z/', $value)
                || ($path === 'service.municipality_code' && $value[0] === '0')) {
                throw new \InvalidArgumentException('Código inválido no perfil de emissão: ' . $key . '.');
            }
        }
        if ($group === 'tax' && in_array($key, ['iss_rate', 'simple_rate', 'total_federal', 'total_state', 'total_municipal'], true)) {
            if ($value === '' && in_array($key, ['iss_rate', 'simple_rate'], true)) { return ''; }
            $value = Decimal::normalize($value);
            $minor = Decimal::minor($value);
            if ($minor < 0) { throw new \InvalidArgumentException('O perfil de emissão não aceita tributos negativos.'); }
            // The distributed DPS XSD represents these as 1V2 and 2V2 decimal percentages.
            if (($key === 'iss_rate' && $minor > 999) || ($key === 'simple_rate' && $minor > 9999)) {
                throw new \InvalidArgumentException($key === 'iss_rate'
                    ? 'Informe alíquota de ISSQN de 0 a 9,99% no perfil.'
                    : 'Informe percentual do Simples de 0 a 99,99% no perfil.');
            }
        }
        return $value;
    }

    /** Read old records without a bulk migration or changing their saved fiscal data. */
    public static function fromRecord(array $record, bool $recipient = false): array
    {
        if (array_key_exists('emission_profile', $record)) {
            if (!is_array($record['emission_profile'])) {
                throw new \InvalidArgumentException('Perfil de emissão armazenado inválido.');
            }
            $result = [];
            foreach (self::fields() as $group => $fields) {
                if (!array_key_exists($group, $record['emission_profile'])) { continue; }
                $values = $record['emission_profile'][$group];
                if (!is_array($values)) { throw new \InvalidArgumentException('Grupo do perfil de emissão armazenado inválido.'); }
                foreach ($fields as $key => $_) {
                    if (array_key_exists($key, $values)) {
                        $result[$group][$key] = Config::text($values[$key], $key === 'description' ? 2000 : 200);
                    }
                }
            }
            return $result;
        }
        $result = [];
        foreach ($recipient ? self::LEGACY_RECIPIENT : self::LEGACY_CLIENT as $legacy => $key) {
            if (array_key_exists($legacy, $record) && $record[$legacy] !== '') {
                $result['service'][$key] = Config::text($record[$legacy], $key === 'description' ? 2000 : 200);
            }
        }
        return $result;
    }

    /** A present editor replaces its sparse map; an older form updates only its legacy fields. */
    public static function fromInput(array $input, array $previous, bool $recipient = false): array
    {
        if (array_key_exists('emission_profile_present', $input)
            && Config::text($input['emission_profile_present'], 1) !== '1') {
            throw new \InvalidArgumentException('Estado do formulário de perfil inválido. Reabra o cadastro.');
        }
        if (array_key_exists('emission_profile_present', $input) || array_key_exists('emission_profile', $input)) {
            $profile = array_key_exists('emission_profile', $input) ? $input['emission_profile'] : [];
            if (!is_array($profile)) { throw new \InvalidArgumentException('Perfil de emissão inválido.'); }
            return self::sanitize($profile);
        }
        $profile = self::fromRecord($previous, $recipient);
        foreach ($recipient ? self::LEGACY_RECIPIENT : self::LEGACY_CLIENT as $legacy => $key) {
            if (!array_key_exists($legacy, $input)) { continue; }
            $value = Config::text($input[$legacy], $key === 'description' ? 2000 : 200);
            if ($value === '') { unset($profile['service'][$key]); }
            else { $profile['service'][$key] = self::value('service', $key, $value); }
        }
        if (isset($profile['service']) && !$profile['service']) { unset($profile['service']); }
        return $profile;
    }

    /** Store one canonical representation; stale legacy fields must never reactivate overrides. */
    public static function canonicalRecord(array $record, array $profile, bool $recipient = false): array
    {
        foreach (array_keys($recipient ? self::LEGACY_RECIPIENT : self::LEGACY_CLIENT) as $key) { unset($record[$key]); }
        $record['emission_profile'] = $profile;
        return $record;
    }

    /** Fiscal values only: this never changes issuer identity, invoice amounts or certificate data. */
    public static function resolve(array $settings, array $client, array $recipient = [], array $items = []): array
    {
        $resolved = self::fields();
        foreach ($resolved as $group => $fields) {
            $resolved[$group] = array_replace($fields, array_intersect_key($settings[$group] ?? [], $fields));
        }
        foreach ([self::fromRecord($client), self::fromRecord($recipient, true)] as $profile) {
            foreach ($profile as $group => $values) { $resolved[$group] = array_replace($resolved[$group], $values); }
        }
        if ($resolved['service']['municipality_code'] === '') {
            $resolved['service']['municipality_code'] = (string) ($settings['provider']['municipality_code'] ?? '');
        }
        if ($resolved['service']['description'] === '') {
            $lines = [];
            foreach ($items as $item) {
                $item = (array) $item;
                $line = trim(($item['description'] ?? '') . ' ' . strip_tags($item['long_description'] ?? ''));
                if ($line !== '') { $lines[] = $line; }
            }
            $resolved['service']['description'] = mb_substr(implode("\n", $lines), 0, 2000);
        }
        return $resolved;
    }
}
