<?php
declare(strict_types=1);
namespace NfseNacional;

final class Config
{
    public static function defaults(): array
    {
        return [
            'environment' => '2', 'production_enabled' => '0', 'series' => '1', 'next_number' => '1', 'use_local_certificate' => '0',
            'variable_simple_rate' => '0', 'variable_iss_rate' => '0', 'variable_iss_rate_source' => 'iss', 'auth_method' => 'certificate', 'auth_unified' => '0', 'auth_certificate_conflict' => '0',
            'automations' => ['paid' => ['mode' => 'disabled', 'delay_minutes' => '0', 'staff_id' => '0'],
                'created' => ['mode' => 'disabled', 'delay_minutes' => '30', 'staff_id' => '0']],
            'automation' => ['mode' => 'disabled', 'trigger' => 'paid', 'delay_minutes' => '30', 'staff_id' => '0'],
            'ibpt_show_expired_warning' => '1', 'ibpt_allow_older_table' => '0',
            'auth' => ['1' => ['method' => 'certificate', 'certificate_source' => 'automatic'], '2' => ['method' => 'certificate', 'certificate_source' => 'automatic']],
            'provider' => ['tax_id' => '', 'name' => '', 'municipality_code' => '', 'municipal_registration' => '',
                'zip' => '', 'street' => '', 'number' => '', 'complement' => '', 'district' => '', 'city' => '', 'state' => '',
                'simple_status' => '1', 'special_regime' => '0', 'simple_apportionment' => '', 'phone' => '', 'email' => ''],
            'service' => ['municipality_code' => '', 'national_code' => '', 'municipal_code' => '', 'nbs' => '', 'description' => ''],
            'tax' => ['iss_taxation' => '1', 'retention' => '1', 'iss_rate' => '', 'total_tax_mode' => 'none', 'total_federal' => '0.00', 'total_state' => '0.00', 'total_municipal' => '0.00', 'simple_rate' => ''],
            'ibscbs' => ['enabled' => '0', 'operation_indicator' => '', 'destination_indicator' => '0', 'cst' => '', 'class_code' => '', 'final_consumer' => '1', 'purpose' => '0'],
            'accounting_recipients' => [],
        ];
    }

    public static function merge(array $stored): array
    {
        $result = array_replace_recursive(self::defaults(), $stored);
        // Upgrades preserve the old single rule in its corresponding type only.
        if (!isset($stored['automations']) && isset($stored['automation'])) {
            $event = $stored['automation']['trigger'] ?? 'paid';
            if (isset($result['automations'][$event])) {
                $result['automations'][$event] = array_intersect_key($stored['automation'], $result['automations'][$event]) + $result['automations'][$event];
            }
        }
        return $result;
    }

    /** Allowlisted input only. A settings form never controls API URLs or file paths. */
    public static function sanitize(array $input, array $previous = []): array
    {
        $result = self::merge($previous);
        foreach (self::defaults() as $key => $default) {
            if ($key === 'accounting_recipients' || $key === 'auth' || $key === 'automations' || str_starts_with($key, 'auth_')) { continue; }
            if (is_array($default)) {
                if (isset($input[$key]) && !is_array($input[$key])) {
                    throw new \InvalidArgumentException('Grupo de configuração inválido.');
                }
                foreach ($default as $child => $_) {
                    if (array_key_exists($child, $input[$key] ?? [])) {
                        $limit = $key === 'provider'
                            ? (['zip' => 10, 'street' => 255, 'number' => 60, 'complement' => 156, 'district' => 60, 'city' => 100, 'state' => 2, 'municipality_code' => 7][$child] ?? 200)
                            : ($child === 'description' ? 2000 : 200);
                        $result[$key][$child] = self::text($input[$key][$child], $limit);
                    }
                }
            } elseif (array_key_exists($key, $input)) {
                $result[$key] = self::text($input[$key], 32);
            }
        }
        if (array_key_exists('auth_method', $input)) {
            if (!is_string($input['auth_method']) || !in_array($input['auth_method'], EnvironmentAuth::METHODS, true)) { throw new \InvalidArgumentException('Método de autenticação inválido.'); }
            $result['auth_method'] = $input['auth_method'];
            $result['auth_unified'] = '1';
        }
        if (array_key_exists('automations', $input)) {
            if (!is_array($input['automations']) || array_diff(array_keys($input['automations']), ['paid', 'created'])) { throw new \InvalidArgumentException('Tipo de automação inválido.'); }
            foreach ($input['automations'] as $event => $config) {
                if (!is_array($config) || array_diff(array_keys($config), ['mode', 'delay_minutes'])) { throw new \InvalidArgumentException('Configuração de automação inválida.'); }
                $config += $result['automations'][$event];
                $config['trigger'] = $event;
                $config = AutomationPolicy::sanitize($config);
                unset($config['trigger']);
                $config['staff_id'] = $result['automations'][$event]['staff_id'];
                $result['automations'][$event] = $config;
            }
        }
        if (array_key_exists('auth', $input)) {
            if (!is_array($input['auth']) || array_diff(array_keys($input['auth']), EnvironmentAuth::ENVIRONMENTS)) {
                throw new \InvalidArgumentException('Ambientes de autenticação inválidos.');
            }
            foreach (EnvironmentAuth::ENVIRONMENTS as $environment) {
                if (!array_key_exists($environment, $input['auth'])) { continue; }
                $profile = $input['auth'][$environment];
                if (!is_array($profile) || array_diff(array_keys($profile), ['method', 'certificate_source'])) {
                    throw new \InvalidArgumentException('Perfil de autenticação inválido.');
                }
                foreach (['method' => EnvironmentAuth::METHODS, 'certificate_source' => EnvironmentAuth::SOURCES] as $field => $allowed) {
                    if (!array_key_exists($field, $profile)) { continue; }
                    if (!is_string($profile[$field]) || !in_array($profile[$field], $allowed, true)) {
                        throw new \InvalidArgumentException('Método ou origem da autenticação inválido.');
                    }
                    $result['auth'][$environment][$field] = $profile[$field];
                }
            }
        }
        if (array_key_exists('accounting_recipients', $input)) {
            $result['accounting_recipients'] = self::accountingRecipients($input['accounting_recipients'], $previous['accounting_recipients'] ?? []);
        }
        foreach (['environment' => ['1', '2'], 'production_enabled' => ['0', '1'], 'use_local_certificate' => ['0', '1'], 'ibpt_show_expired_warning' => ['0', '1'], 'ibpt_allow_older_table' => ['0', '1']] as $key => $allowed) {
            if (!in_array($result[$key], $allowed, true)) {
                throw new \InvalidArgumentException('Opção de configuração inválida: ' . $key . '.');
            }
        }
        if (($result['auth_certificate_conflict'] ?? '0') !== '1') { foreach (EnvironmentAuth::ENVIRONMENTS as $environment) { EnvironmentAuth::profile($result, $environment); } }
        if (!preg_match('/^\d{1,5}$/D', $result['series']) || (int) $result['series'] < 1 || (int) $result['series'] > 49999) {
            throw new \InvalidArgumentException('Informe uma série de aplicativo próprio entre 1 e 49999.');
        }
        if (!preg_match('/^[1-9]\d{0,14}$/D', $result['next_number'])) {
            throw new \InvalidArgumentException('Número inicial da DPS inválido.');
        }
        if (!in_array($result['variable_simple_rate'], ['0', '1'], true)) { throw new \InvalidArgumentException('Opção de alíquota variável inválida.'); }
        if (!in_array($result['variable_iss_rate'], ['0', '1'], true) || !in_array($result['variable_iss_rate_source'], ['simple', 'iss'], true)) { throw new \InvalidArgumentException('Opção de alíquota variável do ISSQN inválida.'); }
        if ($result['variable_iss_rate'] === '1') { $result['tax']['iss_rate'] = $previous['tax']['iss_rate'] ?? self::defaults()['tax']['iss_rate']; }
        if ($result['variable_simple_rate'] === '1') {
            // Keep the fixed configuration for when the feature is disabled again.
            $result['tax']['simple_rate'] = $previous['tax']['simple_rate'] ?? self::defaults()['tax']['simple_rate'];
        }
        $result['automation'] = AutomationPolicy::sanitize($result['automation']);
        // Actor is set by the controller; request data cannot choose the background identity.
        $result['automation']['staff_id'] = (string) ($previous['automation']['staff_id'] ?? '0');
        $result['provider']['tax_id'] = self::taxId($result['provider']['tax_id']);
        $result['provider']['phone'] = preg_replace('/\D+/', '', $result['provider']['phone']);
        $result['provider']['zip'] = preg_replace('/[\s-]+/', '', $result['provider']['zip']);
        if ($result['provider']['zip'] !== '' && !preg_match('/^[0-9]{8}$/D', $result['provider']['zip'])) {
            throw new \InvalidArgumentException('Informe um CEP do estabelecimento com 8 dígitos.');
        }
        $result['provider']['state'] = strtoupper($result['provider']['state']);
        if ($result['provider']['state'] !== '' && !in_array($result['provider']['state'], explode(' ', 'AC AL AP AM BA CE DF ES GO MA MT MS MG PA PB PR PE PI RJ RN RS RO RR SC SP SE TO'), true)) {
            throw new \InvalidArgumentException('Selecione uma UF válida para o estabelecimento.');
        }
        $municipality = $result['provider']['municipality_code'];
        $resolved = CrmData::municipalityCode($result['provider']['city'], $result['provider']['state']);
        if ($municipality === '' && $resolved !== '') { $result['provider']['municipality_code'] = $municipality = $resolved; }
        if ($municipality !== '' && !preg_match('/^[0-9]{7}$/D', $municipality)) {
            throw new \InvalidArgumentException('Informe o código IBGE do estabelecimento com 7 dígitos.');
        }
        if ($municipality !== '' && $result['provider']['state'] !== ''
            && CrmData::municipalityCode($municipality, $result['provider']['state']) !== $municipality) {
            throw new \InvalidArgumentException('O código IBGE do estabelecimento não corresponde à UF informada.');
        }
        if ($resolved !== '' && $municipality !== '' && $resolved !== $municipality) {
            throw new \InvalidArgumentException('O município do estabelecimento não corresponde ao código IBGE informado.');
        }
        $result['service']['national_code'] = self::serviceCode($result['service']['national_code']);
        if (!in_array($result['tax']['total_tax_mode'], ['none', 'amount', 'simple', 'ibpt'], true)) {
            throw new \InvalidArgumentException('Selecione a forma de informar os tributos aproximados.');
        }
        foreach (['total_federal', 'total_state', 'total_municipal'] as $key) {
            $result['tax'][$key] = Decimal::normalize($result['tax'][$key]);
        }
        foreach (['iss_rate', 'simple_rate'] as $key) {
            if ($result['tax'][$key] !== '') {
                $result['tax'][$key] = Decimal::normalize($result['tax'][$key]);
            }
        }
        return $result;
    }

    /** Recipient IDs are generated here; submitted IDs can only refer to this company's saved list. */
    private static function accountingRecipients($input, array $previous): array
    {
        if (!is_array($input)) { throw new \InvalidArgumentException('Lista de destinatários inválida.'); }
        $known = [];
        foreach ($previous as $row) {
            if (is_array($row) && is_string($row['id'] ?? null) && preg_match('/^[a-f0-9]{16}$/D', $row['id'])) { $known[$row['id']] = true; }
        }
        $result = []; $ids = []; $emails = [];
        foreach ($input as $row) {
            if (!is_array($row)) { throw new \InvalidArgumentException('Destinatário do pacote mensal inválido.'); }
            // Reject controls before text normalization; removing a CR/NUL from
            // an address could silently turn it into a different valid recipient.
            foreach (['name', 'email', 'id'] as $field) {
                if (is_string($row[$field] ?? null) && preg_match('/[\x00-\x1f\x7f]/', $row[$field])) {
                    throw new \InvalidArgumentException('Informe nome e e-mail válidos para cada destinatário de e-mail.');
                }
            }
            $name = self::text($row['name'] ?? '', 100);
            $email = self::text($row['email'] ?? '', 254);
            $id = self::text($row['id'] ?? '', 16);
            if ($name === '' && $email === '') { continue; }
            if ($name === '' || strpbrk($name . $email, "\r\n") !== false || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('Informe nome e e-mail válidos para cada destinatário de e-mail.');
            }
            if ($id !== '' && (!isset($known[$id]) || isset($ids[$id]))) { throw new \InvalidArgumentException('A lista de destinatários mudou. Atualize a página antes de salvar.'); }
            $normalizedEmail = strtolower($email);
            if (isset($emails[$normalizedEmail])) { throw new \InvalidArgumentException('O mesmo e-mail está cadastrado mais de uma vez nos destinatários.'); }
            $id = $id === '' ? bin2hex(random_bytes(8)) : $id;
            $ids[$id] = $emails[$normalizedEmail] = true;
            $result[] = ['id' => $id, 'name' => $name, 'email' => $email];
        }
        return $result;
    }

    /** National taxation codes are identifiers, never numbers or a CNAE/NBS value. */
    public static function serviceCode($value): string
    {
        $code = self::text($value, 8);
        if ($code === '') { return ''; }
        if (preg_match('/^\d{2}\.\d{2}\.\d{2}$/D', $code)) { $code = str_replace('.', '', $code); }
        if (!preg_match('/^\d{6}$/D', $code) || !ServiceCatalog::has($code)) {
            throw new \InvalidArgumentException('Selecione um serviço do catálogo nacional da NFS-e. O código deve corresponder à tabela de tributação nacional.');
        }
        return $code;
    }

    public static function text($value, int $max = 200): string
    {
        if (!is_scalar($value) || is_bool($value)) {
            throw new \InvalidArgumentException('Campo textual inválido.');
        }
        $value = trim(str_replace(["\0", "\r"], '', (string) $value));
        if (!preg_match('//u', $value) || mb_strlen($value, 'UTF-8') > $max) {
            throw new \InvalidArgumentException('Texto inválido ou maior que o limite permitido.');
        }
        return $value;
    }

    public static function taxId($value): string
    {
        return strtoupper(preg_replace('/[.\/\-\s]+/', '', self::text($value, 32)));
    }
}
