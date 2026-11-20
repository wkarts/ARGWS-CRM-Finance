<?php
declare(strict_types=1);
namespace NfseNacional;

/** Read-only reuse of the current CRM company and customer records. No remote lookups. */
final class CrmData
{
    private static ?array $municipalities = null;
    private static ?array $municipalityIndex = null;

    public static function provider(array $provider, callable $option): array
    {
        $existing = [
            'tax_id' => self::firstOption($option, ['company_vat']),
            'name' => self::firstOption($option, ['invoice_company_name', 'companyname']),
            'phone' => self::firstOption($option, ['invoice_company_phonenumber']),
            'email' => self::firstOption($option, ['company_email', 'smtp_email']),
        ];
        foreach ($existing as $key => $value) {
            if (trim((string) ($provider[$key] ?? '')) === '' && $value !== '') { $provider[$key] = $value; }
        }
        $addressKeys = ['zip', 'street', 'number', 'complement', 'district', 'city', 'state'];
        $hasLocalAddress = false;
        foreach ($addressKeys as $key) { if (trim((string) ($provider[$key] ?? '')) !== '') { $hasLocalAddress = true; break; } }
        // Import one address as a whole. Never combine a locally edited street
        // with the number, city or postal code of the native company address.
        if (!$hasLocalAddress) {
            $city = self::firstOption($option, ['invoice_company_city']);
            $state = self::state(self::firstOption($option, ['company_state']));
            $native = self::address([
                'address' => self::firstOption($option, ['invoice_company_address']),
                'zip' => self::firstOption($option, ['invoice_company_postal_code']),
                'city' => $city, 'state' => $state,
            ]) + ['city' => $city, 'state' => $state];
            $code = trim((string) ($provider['municipality_code'] ?? ''));
            if ($code === '' || ($native['municipality_code'] !== '' && $code === $native['municipality_code'])) {
                foreach ($addressKeys as $key) { $provider[$key] = $native[$key]; }
                if ($code === '') { $provider['municipality_code'] = $native['municipality_code']; }
            }
        } elseif (trim((string) ($provider['municipality_code'] ?? '')) === '') {
            $provider['municipality_code'] = self::municipalityCode((string) ($provider['city'] ?? ''), (string) ($provider['state'] ?? ''));
        }
        $provider['tax_id'] = strtoupper(preg_replace('/[.\/\-\s]+/', '', (string) ($provider['tax_id'] ?? '')));
        $provider['phone'] = preg_replace('/\D+/', '', (string) ($provider['phone'] ?? ''));
        return $provider;
    }

    private static function firstOption(callable $option, array $names): string
    {
        foreach ($names as $name) {
            $value = $option($name);
            if (is_scalar($value) && !is_bool($value) && trim((string) $value) !== '') { return trim((string) $value); }
        }
        return '';
    }

    private static function value(array $row, array $names): string
    {
        foreach ($names as $name) {
            if (isset($row[$name]) && is_scalar($row[$name]) && !is_bool($row[$name]) && trim((string) $row[$name]) !== '') {
                return trim((string) $row[$name]);
            }
        }
        return '';
    }

    public static function address(array $client): array
    {
        $billing = self::value($client, ['address']) === '' && self::value($client, ['billing_street']) !== '';
        $street = self::value($client, $billing ? ['billing_street'] : ['address']);
        $number = self::value($client, $billing ? ['billing_number'] : ['number', 'address_number', 'numero']);
        // Only split the unambiguous two-part form "Street, number". Never guess a district.
        if (preg_match('/^([^,\r\n]+),\s*(\d{1,10}\s*[A-Za-z]?|S\/N)\s*$/iu', $street, $match)) {
            $street = trim($match[1]);
            if ($number === '') { $number = strtoupper(preg_replace('/\s+/', '', $match[2])); }
        }
        $city = self::value($client, $billing ? ['billing_city'] : ['city']);
        $state = self::value($client, $billing ? ['billing_state'] : ['state']);
        $code = self::value($client, $billing ? ['billing_ibge', 'billing_municipality_code'] : ['ibge', 'municipality_code', 'codigo_ibge']);
        if (!preg_match('/^[0-9]{7}$/D', $code)) { $code = self::municipalityCode($city, $state); }
        return [
            'street' => $street, 'number' => $number,
            'zip' => preg_replace('/\D+/', '', self::value($client, $billing ? ['billing_zip'] : ['zip'])),
            'municipality_code' => $code,
            'district' => self::value($client, $billing ? ['billing_district'] : ['district', 'bairro']),
            'complement' => self::value($client, $billing ? ['billing_complement'] : ['complement', 'complemento']),
        ];
    }

    public static function profileDefaults(array $client, array $contact = []): array
    {
        $address = self::address($client);
        return array_intersect_key($address, array_flip(['municipality_code', 'number', 'district', 'complement'])) + [
            'contact_tax_id' => self::value($contact, ['vat', 'cpf', 'cnpj', 'tax_id']),
        ];
    }

    public static function municipalityCode(string $city, string $state): string
    {
        $uf = self::state($state);
        if ($uf === '' || trim($city) === '') { return ''; }
        if (self::$municipalities === null) {
            $path = dirname(__DIR__, 2) . '/resources/municipios.json';
            self::$municipalities = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
        }
        if (preg_match('/^[0-9]{7}$/D', trim($city))) {
            $row = self::$municipalities[trim($city)] ?? [];
            return ($row['uf'] ?? '') === $uf ? trim($city) : '';
        }
        if (self::$municipalityIndex === null) {
            self::$municipalityIndex = [];
            foreach (self::$municipalities as $code => $row) {
                $key = ($row['uf'] ?? '') . ':' . self::normalize((string) ($row['name'] ?? ''));
                // An ambiguous normalized name is intentionally unusable.
                self::$municipalityIndex[$key] = array_key_exists($key, self::$municipalityIndex) ? '' : (string) $code;
            }
        }
        return self::$municipalityIndex[$uf . ':' . self::normalize($city)] ?? '';
    }

    private static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','ê'=>'e','è'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c']);
        return preg_replace('/[^a-z0-9]+/', '', $value);
    }

    private static function state(string $state): string
    {
        $states = ['AC'=>'Acre','AL'=>'Alagoas','AP'=>'Amapá','AM'=>'Amazonas','BA'=>'Bahia','CE'=>'Ceará','DF'=>'Distrito Federal','ES'=>'Espírito Santo','GO'=>'Goiás','MA'=>'Maranhão','MT'=>'Mato Grosso','MS'=>'Mato Grosso do Sul','MG'=>'Minas Gerais','PA'=>'Pará','PB'=>'Paraíba','PR'=>'Paraná','PE'=>'Pernambuco','PI'=>'Piauí','RJ'=>'Rio de Janeiro','RN'=>'Rio Grande do Norte','RS'=>'Rio Grande do Sul','RO'=>'Rondônia','RR'=>'Roraima','SC'=>'Santa Catarina','SP'=>'São Paulo','SE'=>'Sergipe','TO'=>'Tocantins'];
        $upper = strtoupper(trim($state));
        if (isset($states[$upper])) { return $upper; }
        foreach ($states as $uf => $name) { if (self::normalize($state) === self::normalize($name)) { return $uf; } }
        return '';
    }
}
