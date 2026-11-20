<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
require dirname(__DIR__) . '/autoload.php';

use NfseNacional\RegistrationLookup;
use NfseNacional\RegistrationLookupStore;

$checks = 0; $now = 100000; $directory = sys_get_temp_dir() . '/nfse-registration-' . bin2hex(random_bytes(8));
function lookupCheck(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException('FALHOU: ' . $message); }
    ++$GLOBALS['checks'];
}
function lookupClock(): int { return $GLOBALS['now']; }
function lookupStore(string $company): RegistrationLookupStore { return new RegistrationLookupStore($GLOBALS['directory'], hash('sha256', $company), 'lookupClock'); }
function lookupCnpj(): array { return ['cnpj' => '11222333000181', 'razao_social' => 'Empresa Exemplo', 'nome_fantasia' => 'Exemplo',
    'logradouro' => 'Praça da Sé', 'numero' => '0', 'bairro' => 'Sé', 'complemento' => 'Sala 2', 'municipio' => 'São Paulo', 'uf' => 'SP',
    'cep' => '01001-000', 'email' => 'cadastro@example.test', 'ddd_telefone_1' => '(11) 3000-0000']; }
function lookupCep(): array { return ['cep' => '01001-000', 'logradouro' => 'Praça da Sé', 'complemento' => 'lado ímpar',
    'bairro' => 'Sé', 'localidade' => 'São Paulo', 'uf' => 'SP', 'ibge' => '3550308']; }
try {
    $calls = [];
    $service = new RegistrationLookup(lookupStore('empresa-a'), 1, null, function ($url, $timeout) use (&$calls) { $calls[] = [$url, $timeout]; return lookupCnpj(); });
    $response = $service->lookup('cnpj', '11.222.333/0001-81'); $fields = $response['fields'];
    lookupCheck(count($calls) === 1 && $calls[0][0] === 'https://brasilapi.com.br/api/cnpj/v1/11222333000181', 'consulta usa destino fixo e CNPJ normalizado');
    lookupCheck($calls[0][1] <= 4000, 'cada tentativa tem timeout limitado');
    lookupCheck($fields['name'] === 'Empresa Exemplo' && $fields['tax_id'] === '11222333000181' && $fields['number'] === '0', 'CNPJ fornece identidade, nome e número zero sem perdas');
    lookupCheck($fields['municipality_code'] === '3550308' && $fields['zip'] === '01001000' && $fields['phone'] === '1130000000', 'endereço normalizado e IBGE enriquecido pelo catálogo existente');
    lookupCheck(array_keys($response) === ['fields', 'message'] && !str_contains(json_encode($response), 'brasilapi'), 'retorno não expõe fornecedores ou escolhas técnicas');
    lookupCheck($service->lookup('cnpj', '11222333000181') === $response && count($calls) === 1, 'cache reutiliza dados sem nova chamada externa');
    lookupCheck(!isset($fields['municipal_registration']), 'ausência de inscrição municipal não fabrica um número para emissão');

    foreach ([['cnpj', '11144477735'], ['cnpj', '11222333000180'], ['cnpj', '00000000000000'], ['cep', '01001A00'], ['cep', 'https://127.0.0.1'], ['cnpj', '11222333000181/../../'], ['url', 'https://example.test']] as [$type, $value]) {
        try { $service->lookup($type, $value); throw new RuntimeException('Aceitou entrada inválida'); }
        catch (InvalidArgumentException $error) { lookupCheck(count($calls) === 1, 'entrada inválida rejeitada antes de acessar serviço: ' . $type . '/' . $value); }
    }

    $fallbackCalls = [];
    $fallback = new RegistrationLookup(lookupStore('empresa-fallback'), 2, null, function ($url) use (&$fallbackCalls) {
        $fallbackCalls[] = $url;
        if (str_contains($url, 'brasilapi')) { throw new RuntimeException('503 upstream'); }
        return ['razao_social' => 'Empresa Alternativa', 'estabelecimento' => ['cnpj' => '11222333000181', 'tipo_logradouro' => 'Rua',
            'logradouro' => 'Exemplo', 'numero' => '10', 'cep' => '01001000', 'cidade' => ['nome' => 'São Paulo', 'ibge_id' => 3550308], 'estado' => ['sigla' => 'SP'],
            'inscricoes_estaduais' => [['inscricao_estadual' => '123456789', 'ativo' => true, 'estado' => ['sigla' => 'SP']]]]];
    });
    $alternate = $fallback->lookup('cnpj', '11222333000181');
    lookupCheck(count($fallbackCalls) === 2 && str_contains($fallbackCalls[1], 'publica.cnpj.ws'), 'indisponibilidade alterna serviço automaticamente');
    lookupCheck($alternate['fields']['name'] === 'Empresa Alternativa' && $alternate['fields']['street'] === 'Rua Exemplo', 'retorno alternativo usa o mesmo contrato');
    lookupCheck($alternate['fields']['municipality_code'] === '3550308' && !isset($alternate['fields']['municipal_registration']), 'CNPJws preserva IBGE e não confunde inscrição estadual com municipal');

    $municipal = new RegistrationLookup(lookupStore('empresa-municipal'), 2, null, static fn() => array_replace(lookupCnpj(), ['inscricao_municipal' => '00123456', 'inscricao_estadual' => '99999999']));
    lookupCheck($municipal->lookup('cnpj', '11222333000181')['fields']['municipal_registration'] === '00123456', 'IM explicitamente retornada mantém zeros à esquerda e não usa inscrição estadual');
    $oversizedMunicipal = new RegistrationLookup(lookupStore('empresa-municipal-invalid'), 2, null, static fn() => array_replace(lookupCnpj(), ['inscricao_municipal' => '1234567890123456']));
    lookupCheck(!isset($oversizedMunicipal->lookup('cnpj', '11222333000181')['fields']['municipal_registration']), 'IM além do limite não é truncada em outro identificador');
    $reportedCode = new RegistrationLookup(lookupStore('empresa-ibge-explicit'), 2, null, static fn() => array_replace(lookupCnpj(), ['municipio' => '', 'codigo_municipio_ibge' => 3550308]));
    lookupCheck($reportedCode->lookup('cnpj', '11222333000181')['fields']['municipality_code'] === '3550308', 'IBGE retornado é aceito pelo catálogo mesmo sem o nome do município');

    $cepCalls = [];
    $cep = new RegistrationLookup(lookupStore('empresa-cep'), 3, null, function ($url) use (&$cepCalls) { $cepCalls[] = $url; return lookupCep(); });
    $postal = $cep->lookup('cep', '01001-000')['fields'];
    lookupCheck($cepCalls === ['https://viacep.com.br/ws/01001000/json/'] && $postal['municipality_code'] === '3550308', 'CEP utiliza consulta automática e retorna município IBGE');
    lookupCheck(!isset($postal['number'], $postal['complement'], $postal['tax_id'], $postal['name']), 'consulta CEP não substitui número, complemento particular ou identidade');
    $postalFallback = new RegistrationLookup(lookupStore('empresa-cep-fallback'), 3, null, static function ($url) {
        return str_contains($url, 'viacep') ? ['erro' => true] : ['cep' => '01001000', 'street' => 'Praça da Sé', 'neighborhood' => 'Sé', 'city' => 'São Paulo', 'state' => 'SP'];
    });
    lookupCheck($postalFallback->lookup('cep', '01001000')['fields']['municipality_code'] === '3550308', 'fallback CEP usa cidade e UF para obter IBGE');

    $wrong = new RegistrationLookup(lookupStore('empresa-identity'), 4, null, static fn() => array_replace(lookupCnpj(), ['cnpj' => '12345678000195']));
    lookupCheck($wrong->lookup('cnpj', '11222333000181')['fields'] === [], 'resposta de outro CNPJ nunca preenche cadastro');
    $wrongCep = new RegistrationLookup(lookupStore('empresa-cep-identity'), 4, null, static fn() => array_replace(lookupCep(), ['cep' => '40000000']));
    lookupCheck($wrongCep->lookup('cep', '01001000')['fields'] === [], 'resposta de outro CEP é rejeitada');
    $empty = new RegistrationLookup(lookupStore('empresa-empty'), 4, null, static fn() => ['cnpj' => '11222333000181']);
    lookupCheck($empty->lookup('cnpj', '11222333000181')['fields'] === [], 'identificador isolado não é tratado como cadastro localizado');

    $tenantCalls = 0; $externalCalls = 0;
    $tenant = new RegistrationLookup(lookupStore('empresa-native'), 5,
        function ($type, $value) use (&$tenantCalls) { ++$tenantCalls; return ['fields' => ['cnpj' => $value, 'legal_name' => 'Reutilizada', 'cep' => '01001000', 'city' => 'São Paulo', 'state' => 'SP', 'ibge' => '3550308', 'municipal_registration' => '0098765'], 'message' => 'internal']; },
        function () use (&$externalCalls) { ++$externalCalls; throw new RuntimeException('not expected'); });
    lookupCheck($tenant->lookup('cnpj', '11222333000181')['fields']['name'] === 'Reutilizada' && $tenantCalls === 1 && $externalCalls === 0, 'serviço existente é reutilizado sem nova sequência pública');
    lookupCheck($tenant->lookup('cnpj', '11222333000181')['fields']['municipal_registration'] === '0098765', 'integração da aplicação preserva IM explícita para preencher parâmetros do emitente');
    $denied = new RegistrationLookup(lookupStore('empresa-denied'), 5, static function () { throw new RuntimeException('Acesso não autorizado', 403); }, function () use (&$externalCalls) { ++$externalCalls; return lookupCnpj(); });
    try { $denied->lookup('cnpj', '11222333000181'); throw new RuntimeException('Acesso aceito'); }
    catch (RuntimeException $error) { lookupCheck($error->getCode() === 403 && $externalCalls === 0, 'negativa da integração não é contornada por outro fornecedor'); }

    $otherCalls = 0;
    $other = new RegistrationLookup(lookupStore('empresa-b'), 1, null, function () use (&$otherCalls) { ++$otherCalls; return array_replace(lookupCnpj(), ['razao_social' => 'Outra empresa']); });
    lookupCheck($other->lookup('cnpj', '11222333000181')['fields']['name'] === 'Outra empresa' && $otherCalls === 1, 'cache pertence ao contexto da empresa');
    lookupCheck($service->lookup('cnpj', '11222333000181')['fields']['name'] === 'Empresa Exemplo', 'consulta de outra empresa preserva cache da primeira');
    $limited = new RegistrationLookup(lookupStore('empresa-rate'), 8, null, static fn() => lookupCnpj());
    for ($i = 0; $i < 20; ++$i) { $limited->lookup('cnpj', '11222333000181'); }
    try { $limited->lookup('cnpj', '11222333000181'); throw new RuntimeException('Limite ignorado'); }
    catch (RuntimeException $error) { lookupCheck($error->getCode() === 429, 'limite por usuário também protege consultas em cache'); }
    $GLOBALS['now'] += 61;
    lookupCheck($limited->lookup('cnpj', '11222333000181')['fields']['name'] === 'Empresa Exemplo', 'limite é liberado após a janela');
    $GLOBALS['now'] += 21601;
    $service->lookup('cnpj', '11222333000181');
    lookupCheck(count($calls) === 2, 'cache expirado é atualizado');

    $budget = lookupStore('empresa-provider');
    for ($i = 0; $i < 3; ++$i) { $budget->hit('provider:cnpjws', 3, 60); }
    try { $budget->hit('provider:cnpjws', 3, 60); throw new RuntimeException('Limite ignorado'); }
    catch (RuntimeException $error) { lookupCheck($error->getCode() === 429, 'limite do fornecedor é atômico e compartilhado entre usuários da instalação'); }
    foreach (glob($directory . '/*.php') as $file) { lookupCheck(str_starts_with((string) file_get_contents($file), "<?php exit; ?>\n"), 'cache não disponibiliza conteúdo via execução PHP'); }
    echo 'OK: ' . $checks . " verificações de consulta cadastral.\n";
} finally {
    foreach (glob($directory . '/*.php') ?: [] as $file) { unlink($file); }
    if (is_dir($directory)) { rmdir($directory); }
}
