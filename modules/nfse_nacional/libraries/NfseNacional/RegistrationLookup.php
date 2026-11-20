<?php
declare(strict_types=1);
namespace NfseNacional;

/** Public registration lookup. It never changes a CRM record or fiscal document. */
final class RegistrationLookup
{
    private const PROVIDERS = ['cnpj' => ['brasilapi', 'cnpjws'], 'cep' => ['viacep', 'brasilapi']];
    private \Closure $clock;
    private ?\Closure $adapter;
    private \Closure $http;

    public function __construct(private RegistrationLookupStore $store, private int $staffId,
        ?callable $adapter = null, ?callable $http = null, ?callable $clock = null)
    {
        if ($staffId < 1) { throw new \InvalidArgumentException('Sessão de consulta inválida.'); }
        $this->adapter = $adapter ? \Closure::fromCallable($adapter) : null;
        $this->http = $http ? \Closure::fromCallable($http) : static fn(string $url, int $timeout): array => self::request($url, $timeout);
        $this->clock = $clock ? \Closure::fromCallable($clock) : static fn(): float => microtime(true);
    }

    /** Resolve everything on the server; the browser supplies only the lookup type and identifier. */
    public static function current(): self
    {
        $context = CompanyContext::current()->descriptor('');
        $ci = get_instance();
        $directory = (string) ($ci->config->item('cache_path') ?: APPPATH . 'cache/');
        $store = new RegistrationLookupStore(rtrim($directory, '/\\') . '/nfse_nacional_lookup', $context['scope']);
        $adapter = null;
        $app = $GLOBALS['pme_app'] ?? null;
        $actor = null;
        if ($app instanceof \Multiempresa\Application\App && class_exists('Multiempresa\\Application\\Lookups')) {
            if ($context['runtime'] === 'multiempresa') {
                $actor = $app->auth->refresh($context['user_id']);
            } elseif (defined('PME_PRIMARY_ROUTE') && PME_PRIMARY_ROUTE === true
                && (string) ($app->config['native_prefix'] ?? '') === $context['prefix']) {
                $actor = $app->auth->syncNative($context['staff_id']);
            }
        }
        if ($actor !== null) {
            // CompanyContext already verified the company, organization, staff mapping and membership.
            $adapter = static function (string $type, string $value) use ($app, $actor): array {
                $app->limits->hit('lookup:' . $actor['id'], 20, 60);
                return (new \Multiempresa\Application\Lookups($app))->lookup($actor, $type, $value);
            };
        }
        return new self($store, $context['staff_id'], $adapter);
    }

    public function lookup(string $type, string $value): array
    {
        $value = self::identifier($type, $value);
        $this->store->hit('staff:' . $this->staffId, 20, 60);
        $key = hash('sha256', 'registration-v2:' . $type . ':' . $value);
        $cached = $this->store->get($key);
        if ($cached !== null) { return self::response($cached); }

        $fields = [];
        if ($this->adapter !== null) {
            // A present integration is authoritative. Never bypass a denied access or its provider budgets.
            $result = ($this->adapter)($type, $value);
            if (is_array($result['fields'] ?? null)) {
                $fields = self::clean($type, $value, self::nativeFields($result['fields']));
            }
        } else {
            $deadline = ($this->clock)() + 8;
            foreach (self::PROVIDERS[$type] as $provider) {
                $remaining = (int) floor(($deadline - ($this->clock)()) * 1000);
                if ($remaining < 100) { break; }
                try {
                    if ($provider === 'cnpjws') { $this->store->hit('provider:cnpjws', 3, 60); }
                    $raw = ($this->http)(self::url($provider, $type, $value), min(4000, $remaining));
                    if (!is_array($raw) || !empty($raw['erro']) || (isset($raw['status']) && is_numeric($raw['status']) && (int) $raw['status'] >= 400)) { continue; }
                    $fields = self::clean($type, $value, self::map($provider, $type, $raw));
                    // Do not combine the address of one data source with an older address from another.
                    if ($fields !== []) { break; }
                } catch (\Throwable $error) { $fields = []; }
            }
        }
        $required = $type === 'cnpj' ? ['name', 'street', 'zip', 'municipality_code'] : ['city', 'state', 'municipality_code'];
        $ttl = $fields === [] ? 30 : (!array_diff($required, array_keys($fields)) ? 21600 : 300);
        $this->store->put($key, $fields, $ttl);
        return self::response($fields);
    }

    public static function identifier(string $type, string $value): string
    {
        if (strlen($value) > 40 || !in_array($type, ['cnpj', 'cep'], true)) {
            throw new \InvalidArgumentException('Consulta inválida.');
        }
        if ($type === 'cnpj') {
            $value = strtoupper(preg_replace('/\s+/', '', trim($value)) ?? '');
            if (!preg_match('/\A(?:[A-Z0-9]{12}[0-9]{2}|[A-Z0-9]{2}\.[A-Z0-9]{3}\.[A-Z0-9]{3}\/[A-Z0-9]{4}-[0-9]{2})\z/D', $value)) {
                throw new \InvalidArgumentException('Informe um CNPJ válido para consultar.');
            }
            $value = str_replace(['.', '/', '-'], '', $value);
            if (!preg_match('/\A[A-Z0-9]{12}[0-9]{2}\z/D', $value) || !Validator::taxId($value)) {
                throw new \InvalidArgumentException('Informe um CNPJ válido para consultar.');
            }
        } else {
            $value = preg_replace('/[\s-]+/', '', trim($value)) ?? '';
            if (!preg_match('/\A[0-9]{8}\z/D', $value)) { throw new \InvalidArgumentException('Informe um CEP com 8 dígitos.'); }
        }
        return $value;
    }

    private static function response(array $fields): array
    {
        return ['fields' => $fields, 'message' => $fields
            ? 'Dados localizados. Confira o preenchimento antes de salvar.'
            : 'Não foi possível localizar os dados agora. Tente novamente ou preencha os campos.'];
    }

    private static function nativeFields(array $fields): array
    {
        foreach (['cnpj' => 'tax_id', 'legal_name' => 'name', 'cep' => 'zip', 'ibge' => 'municipality_code', 'inscricao_municipal' => 'municipal_registration'] as $from => $to) {
            if (array_key_exists($from, $fields)) { $fields[$to] = $fields[$from]; }
        }
        return $fields;
    }

    private static function map(string $provider, string $type, array $raw): array
    {
        if ($type === 'cep') {
            return ['zip' => $raw['cep'] ?? '', 'street' => $raw['logradouro'] ?? $raw['street'] ?? '',
                'district' => $raw['bairro'] ?? $raw['neighborhood'] ?? '', 'city' => $raw['localidade'] ?? $raw['city'] ?? '',
                'state' => $raw['uf'] ?? $raw['state'] ?? '', 'municipality_code' => $raw['ibge'] ?? ''];
        }
        if ($provider === 'cnpjws') {
            $establishment = is_array($raw['estabelecimento'] ?? null) ? $raw['estabelecimento'] : [];
            return ['tax_id' => $establishment['cnpj'] ?? '', 'name' => $raw['razao_social'] ?? '',
                'trade_name' => $establishment['nome_fantasia'] ?? '', 'email' => $establishment['email'] ?? '',
                'phone' => self::text($establishment['ddd1'] ?? '') . self::text($establishment['telefone1'] ?? ''),
                'zip' => $establishment['cep'] ?? '', 'street' => trim(self::text($establishment['tipo_logradouro'] ?? '') . ' ' . self::text($establishment['logradouro'] ?? '')),
                'number' => $establishment['numero'] ?? '', 'complement' => $establishment['complemento'] ?? '',
                'district' => $establishment['bairro'] ?? '', 'city' => $establishment['cidade']['nome'] ?? '',
                'state' => $establishment['estado']['sigla'] ?? '', 'municipality_code' => $establishment['cidade']['ibge_id'] ?? '',
                // Municipal registration is distinct from inscricoes_estaduais.
                'municipal_registration' => $establishment['inscricao_municipal'] ?? $raw['inscricao_municipal'] ?? ''];
        }
        return ['tax_id' => $raw['cnpj'] ?? '', 'name' => $raw['razao_social'] ?? '', 'trade_name' => $raw['nome_fantasia'] ?? '',
            'email' => $raw['email'] ?? '', 'phone' => $raw['ddd_telefone_1'] ?? '', 'zip' => $raw['cep'] ?? '',
            'street' => $raw['logradouro'] ?? '', 'number' => $raw['numero'] ?? '', 'complement' => $raw['complemento'] ?? '',
            'district' => $raw['bairro'] ?? '', 'city' => $raw['municipio'] ?? '', 'state' => $raw['uf'] ?? '',
            'municipality_code' => $raw['codigo_municipio_ibge'] ?? '', 'municipal_registration' => $raw['inscricao_municipal'] ?? ''];
    }

    private static function text(mixed $value): string
    {
        if (!is_scalar($value) || is_bool($value)) { return ''; }
        return mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]/', '', strip_tags((string) $value)) ?? ''), 0, 300, 'UTF-8');
    }

    private static function clean(string $type, string $identifier, array $raw): array
    {
        $keys = $type === 'cnpj'
            ? ['tax_id', 'name', 'trade_name', 'email', 'phone', 'street', 'number', 'complement', 'district', 'city', 'state', 'zip', 'municipality_code', 'municipal_registration']
            : ['street', 'district', 'city', 'state', 'zip', 'municipality_code'];
        $fields = [];
        foreach ($keys as $key) { $value = self::text($raw[$key] ?? ''); if ($value !== '') { $fields[$key] = $value; } }
        $identityKey = $type === 'cnpj' ? 'tax_id' : 'zip';
        if (!isset($fields[$identityKey])) { return []; }
        try { if (self::identifier($type, $fields[$identityKey]) !== $identifier) { return []; } }
        catch (\InvalidArgumentException $error) { return []; }
        $fields[$identityKey] = $identifier;
        if (isset($fields['zip'])) {
            $fields['zip'] = preg_replace('/[\s-]+/', '', $fields['zip']);
            if (!preg_match('/\A[0-9]{8}\z/D', $fields['zip'])) { unset($fields['zip']); }
        }
        if (isset($fields['state'])) {
            $fields['state'] = strtoupper($fields['state']);
            if (!in_array($fields['state'], explode(' ', 'AC AL AP AM BA CE DF ES GO MA MT MS MG PA PB PR PE PI RJ RN RS RO RR SC SP SE TO'), true)) { unset($fields['state']); }
        }
        if (isset($fields['email']) && !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) { unset($fields['email']); }
        if (isset($fields['municipal_registration']) && mb_strlen($fields['municipal_registration'], 'UTF-8') > 15) {
            // Do not truncate a registration number into a different identifier.
            unset($fields['municipal_registration']);
        }
        if (isset($fields['phone'])) {
            $fields['phone'] = preg_replace('/\D+/', '', $fields['phone']);
            if (strlen($fields['phone']) < 8 || strlen($fields['phone']) > 15) { unset($fields['phone']); }
        }
        $cityCode = CrmData::municipalityCode($fields['city'] ?? '', $fields['state'] ?? '');
        if ($cityCode !== '') { $fields['municipality_code'] = $cityCode; }
        elseif (!isset($fields['municipality_code'])
            || CrmData::municipalityCode($fields['municipality_code'], $fields['state'] ?? '') !== $fields['municipality_code']) {
            unset($fields['municipality_code']);
        }
        $useful = $type === 'cnpj' ? ['name', 'trade_name'] : ['city', 'street', 'district'];
        return array_intersect($useful, array_keys($fields)) ? $fields : [];
    }

    private static function url(string $provider, string $type, string $value): string
    {
        if ($type === 'cep') { return $provider === 'viacep' ? 'https://viacep.com.br/ws/' . $value . '/json/' : 'https://brasilapi.com.br/api/cep/v2/' . $value; }
        return $provider === 'cnpjws' ? 'https://publica.cnpj.ws/cnpj/' . $value : 'https://brasilapi.com.br/api/cnpj/v1/' . $value;
    }

    private static function request(string $url, int $timeout): array
    {
        if (!function_exists('curl_init')) { throw new \RuntimeException('Consulta indisponível.'); }
        $curl = curl_init($url); $body = '';
        curl_setopt_array($curl, [CURLOPT_HTTPGET => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT_MS => min(1500, $timeout), CURLOPT_TIMEOUT_MS => $timeout,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_USERAGENT => 'Perfex-NFSe-Nacional/RegistrationLookup',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 1048576) { return 0; }
                $body .= $chunk; return strlen($chunk);
            },
        ]);
        try {
            $ok = curl_exec($curl); $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($ok === false || $status !== 200) { throw new \RuntimeException('Consulta indisponível.'); }
            $result = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($result)) { throw new \RuntimeException('Resposta cadastral inválida.'); }
            return $result;
        } finally { curl_close($curl); }
    }
}
