<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
require dirname(__DIR__) . '/autoload.php';

use NfseNacional\CrmData;
use NfseNacional\Config;

$checks = 0;
function crmCheck(bool $result, string $label): void
{
    if (!$result) { throw new RuntimeException('FALHOU: ' . $label); }
    ++$GLOBALS['checks'];
}

$options = ['company_vat' => '12.345.678/0001-95', 'invoice_company_name' => 'EMPRESA ATIVA',
    'companyname' => 'Nome do CRM', 'invoice_company_city' => 'Salvador', 'company_state' => 'Bahia',
    'invoice_company_address' => 'Rua do Estabelecimento, 12A', 'invoice_company_postal_code' => '40030-170',
    'invoice_company_phonenumber' => '(71) 3333-4444', 'smtp_email' => 'empresa@example.invalid'];
$read = static fn($key) => $GLOBALS['options'][$key] ?? false;
$provider = CrmData::provider(Config::defaults()['provider'], $read);
crmCheck($provider['name'] === 'EMPRESA ATIVA' && $provider['tax_id'] === '12345678000195', 'identidade vem do cadastro da empresa ativa');
crmCheck($provider['municipality_code'] === '2927408', 'cidade e UF do emitente resolvem código IBGE');
crmCheck($provider['phone'] === '7133334444' && $provider['email'] === 'empresa@example.invalid', 'contato nativo é reutilizado');
crmCheck($provider['street'] === 'Rua do Estabelecimento' && $provider['number'] === '12A'
    && $provider['zip'] === '40030170' && $provider['city'] === 'Salvador' && $provider['state'] === 'BA', 'endereço nativo do emitente é importado como conjunto e normalizado');
crmCheck($provider['district'] === '' && $provider['complement'] === '', 'não inventa bairro ou complemento ausente no cadastro nativo');
$savedAddress = array_replace(Config::defaults()['provider'], ['street' => 'Rua fiscal revisada', 'number' => '0',
    'city' => 'São Paulo', 'state' => 'SP', 'zip' => '01001000']);
$localAddress = CrmData::provider($savedAddress, $read);
crmCheck($localAddress['street'] === 'Rua fiscal revisada' && $localAddress['number'] === '0'
    && $localAddress['city'] === 'São Paulo' && $localAddress['state'] === 'SP' && $localAddress['municipality_code'] === '3550308', 'endereço fiscal local prevalece e resolve IBGE na própria UF');
$partialAddress = CrmData::provider(array_replace(Config::defaults()['provider'], ['street' => 'Rua digitada']), $read);
crmCheck($partialAddress['street'] === 'Rua digitada' && $partialAddress['number'] === ''
    && $partialAddress['zip'] === '' && $partialAddress['city'] === '' && $partialAddress['state'] === ''
    && $partialAddress['municipality_code'] === '', 'endereço local parcial não recebe número, CEP ou município de outro endereço');
$foreignMunicipality = CrmData::provider(array_replace(Config::defaults()['provider'], ['municipality_code' => '3550308']), $read);
crmCheck($foreignMunicipality['municipality_code'] === '3550308' && $foreignMunicipality['street'] === ''
    && $foreignMunicipality['zip'] === '' && $foreignMunicipality['city'] === '', 'IBGE fiscal de outro município impede importar endereço nativo incompatível');
$provider['name'] = 'Nome fiscal revisado'; $provider['municipality_code'] = '3550308';
$again = CrmData::provider($provider, $read);
crmCheck($again['name'] === 'Nome fiscal revisado' && $again['municipality_code'] === '3550308', 'preferências fiscais explícitas não são sobrescritas');
crmCheck(CrmData::municipalityCode('sao paulo', 'sp') === '3550308', 'acentos e caixa não exigem redigitação da cidade');
crmCheck(CrmData::municipalityCode('Feira de Santana', 'BA') === '2910800', 'segunda cidade é resolvida dentro da UF');
crmCheck(CrmData::municipalityCode('Salvador', 'SP') === '', 'não adivinha município de UF diferente');
crmCheck(CrmData::municipalityCode('Salvador', '') === '', 'sem UF não presume município');
crmCheck(CrmData::municipalityCode('Cidade inexistente', 'BA') === '', 'cadastro não identificado permanece para revisão');
crmCheck(CrmData::municipalityCode('2927408', 'BA') === '2927408' && CrmData::municipalityCode('2927408', 'SP') === '', 'código numérico também precisa corresponder à UF');

$client = ['address' => 'Rua de Exemplo, 125A', 'city' => 'Salvador', 'state' => 'BA', 'zip' => '40000-000',
    'district' => 'Centro', 'complement' => 'Sala 2', 'vat' => '12345678000195'];
$address = CrmData::address($client);
crmCheck($address['street'] === 'Rua de Exemplo' && $address['number'] === '125A', 'endereço em formato inequívoco fornece número');
crmCheck($address['district'] === 'Centro' && $address['complement'] === 'Sala 2', 'campos existentes de bairro e complemento são aproveitados');
crmCheck($address['zip'] === '40000000' && $address['municipality_code'] === '2927408', 'CEP e município existentes normalizados');
$ambiguous = CrmData::address(['address' => 'Rua, Quadra, Lote 20', 'city' => 'Salvador', 'state' => 'BA']);
crmCheck($ambiguous['street'] === 'Rua, Quadra, Lote 20' && $ambiguous['number'] === '', 'endereço ambíguo não produz número inventado');
$explicit = CrmData::address($client + ['number' => '999']);
crmCheck($explicit['number'] === '999' && $explicit['street'] === 'Rua de Exemplo', 'número já cadastrado tem precedência, sem duplicar número no logradouro');
$billing = CrmData::address(['address' => '', 'billing_street' => 'Rua de Cobrança, 30', 'billing_city' => 'Salvador', 'billing_state' => 'BA', 'billing_zip' => '40000000']);
crmCheck($billing['number'] === '30' && $billing['municipality_code'] === '2927408', 'endereço de cobrança reutilizado quando endereço geral está vazio');
$billing = CrmData::address(['address' => '', 'number' => '99', 'district' => 'Bairro antigo', 'complement' => 'Complemento antigo', 'billing_street' => 'Rua de Cobrança, 30', 'billing_city' => 'Salvador', 'billing_state' => 'BA']);
crmCheck($billing['street'] === 'Rua de Cobrança' && $billing['number'] === '30' && $billing['district'] === '' && $billing['complement'] === '', 'não mistura complementos de endereços diferentes');
$defaults = CrmData::profileDefaults($client);
crmCheck($defaults['contact_tax_id'] === '', 'documento do responsável não é usado como CPF do contato');
$defaults = CrmData::profileDefaults($client, ['cpf' => '111.444.777-35']);
crmCheck($defaults['contact_tax_id'] === '111.444.777-35', 'documento próprio do contato é aproveitado se existente');

class App_Model {}
require dirname(__DIR__) . '/models/Nfse_nacional_model.php';
function get_option($key) { return $GLOBALS['options'][$key] ?? false; }
class CrmSettingsModel extends Nfse_nacional_model
{
    public array $row = ['settings_json' => '{}', 'certificate_enc' => null];
    public function __construct() {}
    public function settingsRow(): array { return $this->row; }
}
$model = new CrmSettingsModel();
crmCheck($model->settings()['use_local_certificate'] === '0', 'instalação nova mantém busca automática');
$model->row['certificate_enc'] = 'encrypted-existing-credential';
crmCheck($model->settings()['use_local_certificate'] === '1', 'atualização preserva prioridade de certificado local da versão0.1');
$model->row['settings_json'] = '{"use_local_certificate":"0"}';
crmCheck($model->settings()['use_local_certificate'] === '0', 'seleção explícita não é revertida pela migração em leitura');
crmCheck($model->settings()['provider']['name'] === 'EMPRESA ATIVA', 'modelo fornece configuração preenchida pela empresa ativa');
$options['company_vat'] = '99.999.999/0001-91'; $options['invoice_company_name'] = 'OUTRA EMPRESA';
$options['invoice_company_address'] = 'Avenida da Outra Empresa, 85';
$options['invoice_company_city'] = 'São Paulo'; $options['company_state'] = 'SP'; $options['invoice_company_postal_code'] = '01001-000';
crmCheck($model->settings()['provider']['name'] === 'OUTRA EMPRESA' && $model->settings()['provider']['tax_id'] === '99999999000191', 'nenhum cache de identidade vaza após troca de empresa');
crmCheck($model->settings()['provider']['street'] === 'Avenida da Outra Empresa'
    && $model->settings()['provider']['number'] === '85' && $model->settings()['provider']['municipality_code'] === '3550308', 'troca de empresa reutiliza exclusivamente seu endereço atual');
echo 'OK: ' . $checks . " verificações de reaproveitamento cadastral e atualização.\n";
