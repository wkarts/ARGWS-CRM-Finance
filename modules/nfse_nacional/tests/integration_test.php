<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Executar: php modules/nfse_nacional/tests/integration_test.php
// Isolado: não conecta ao Perfex, não lê certificados e não acessa a rede.
define('BASEPATH', __DIR__ . '/');
define('APP_ENC_KEY', str_repeat('T', 32));

final class IntegrationQueryResult
{
    private array $rows;
    public function __construct(array $rows) { $this->rows = $rows; }
    public function row_array(): array { return $this->rows[0] ?? []; }
}

final class IntegrationDatabase
{
    public string $database = 'company_a';
    public string $hostname = 'database';
    public array $tables = [];
    private array $where = [];
    public function select(string $columns): self { return $this; }
    public function where(string $field, $value): self { $this->where[$field] = $value; return $this; }
    public function limit(int $limit): self { return $this; }
    public function table_exists(string $table): bool { return array_key_exists($table, $this->tables); }
    public function get(string $table): IntegrationQueryResult
    {
        $filters = $this->where;
        $this->where = [];
        $rows = array_values(array_filter($this->tables[$table] ?? [], static function (array $row) use ($filters): bool {
            foreach ($filters as $field => $expected) {
                if (!array_key_exists($field, $row) || (string) $row[$field] !== (string) $expected) {
                    return false;
                }
            }
            return true;
        }));
        return new IntegrationQueryResult($rows);
    }
}

$integrationState = ['logged_in' => true, 'admin' => false, 'staff_id' => 8,
    'prefix' => 'a_', 'permissions' => [], 'options' => []];
$integrationCi = (object) ['db' => new IntegrationDatabase()];

function &get_instance() { return $GLOBALS['integrationCi']; }
function db_prefix(): string { return $GLOBALS['integrationState']['prefix']; }
function is_staff_logged_in(): bool { return $GLOBALS['integrationState']['logged_in']; }
function is_admin(): bool { return $GLOBALS['integrationState']['admin']; }
function get_staff_user_id(): int { return $GLOBALS['integrationState']['staff_id']; }
function staff_can(string $action, string $feature): bool { return in_array($action, $GLOBALS['integrationState']['permissions'][$feature] ?? [], true); }
function get_option(string $key) { return $GLOBALS['integrationState']['options'][$key] ?? ''; }

require dirname(__DIR__) . '/helpers/nfse_nacional_helper.php';
require dirname(__DIR__) . '/autoload.php';

$integrationChecks = 0;
function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FALHOU: ' . $message);
    }
    ++$GLOBALS['integrationChecks'];
}

$integrationCi->db->tables = [
    'a_clients' => [['userid' => 10], ['userid' => 11]],
    'a_customer_admins' => [['customer_id' => 10, 'staff_id' => 8]],
    'a_invoices' => [
        ['id' => 101, 'clientid' => 10, 'addedfrom' => 8, 'sale_agent' => 0],
        ['id' => 102, 'clientid' => 11, 'addedfrom' => 8, 'sale_agent' => 0],
        ['id' => 103, 'clientid' => 10, 'addedfrom' => 9, 'sale_agent' => 8],
    ],
    'b_clients' => [['userid' => 20]],
    'b_customer_admins' => [['customer_id' => 20, 'staff_id' => 8]],
    'b_invoices' => [['id' => 201, 'clientid' => 20, 'addedfrom' => 8, 'sale_agent' => 0]],
];

check(!nfse_nacional_can('view'), 'sem permissão fiscal, visualização negada');
$integrationState['permissions'] = ['nfse_nacional' => ['transmit']];
check(!nfse_nacional_can('transmit'), 'transmissão isolada não concede visualização');
$integrationState['admin'] = true;
$integrationState['logged_in'] = false;
check(!nfse_nacional_can('settings'), 'sem sessão autenticada, flag admin não autoriza');
$integrationState['logged_in'] = true;
check(nfse_nacional_can('settings'), 'administrador autenticado possui configuração');
check(!nfse_nacional_can('arbitrary'), 'capacidade desconhecida é sempre negada');
$integrationState['admin'] = false;
$integrationState['permissions'] = ['nfse_nacional' => ['view', 'transmit'], 'invoices' => ['view_own']];

check(nfse_nacional_client_allowed(10), 'administrador atribuído ao cliente pode acessar');
check(!nfse_nacional_client_allowed(11), 'cliente sem atribuição é negado');
check(!nfse_nacional_client_allowed(20), 'cliente que existe só em outra empresa é negado');
check(!nfse_nacional_client_allowed('10 OR 1=1'), 'identificador textual inválido não é convertido em inteiro');
check(nfse_nacional_invoice_allowed(101), 'fatura própria de cliente atribuído é permitida');
check(!nfse_nacional_invoice_allowed(102), 'fatura própria não ignora bloqueio do cliente');
check(!nfse_nacional_invoice_allowed(201), 'fatura de outra empresa não é resolvida');
check(!nfse_nacional_invoice_allowed(103), 'agente de venda não acessa fatura por padrão');
$integrationState['options']['allow_staff_view_invoices_assigned'] = '1';
check(nfse_nacional_invoice_allowed(103), 'opção nativa explícita permite fatura atribuída');
unset($integrationState['options']['allow_staff_view_invoices_assigned']);
$integrationState['permissions']['customers'] = ['view'];
check(nfse_nacional_client_allowed(11), 'permissão global clientes dá acesso a clientes existentes');
check(!nfse_nacional_invoice_allowed(103, (object) ['id' => 103, 'clientid' => 10, 'addedfrom' => 8]), 'objeto externo não falsifica proprietário de fatura');
$integrationState['permissions']['invoices'] = [];
check(!nfse_nacional_invoice_allowed(101), 'acesso global cliente não concede acesso a faturas');

$scopeA = nfse_nacional_scope();
$integrationState['prefix'] = 'b_';
$integrationCi->db->database = 'company_b';
$scopeB = nfse_nacional_scope();
check($scopeA !== $scopeB, 'empresas diferentes têm escopos criptográficos diferentes');
$cryptoA = new NfseNacional\Crypto(APP_ENC_KEY, $scopeA);
$cryptoB = new NfseNacional\Crypto(APP_ENC_KEY, $scopeB);
$ciphertext = $cryptoA->encrypt('documento fiscal restrito');
$crossTenantDenied = false;
try { $cryptoB->decrypt($ciphertext); } catch (RuntimeException $expected) { $crossTenantDenied = true; }
check($crossTenantDenied, 'conteúdo cifrado não pode ser aberto em outra empresa mesmo com a mesma chave base');
check(!nfse_nacional_client_allowed(10), 'troca de contexto não recupera cadastro da empresa anterior');

define('PME_CONTEXT_READY', true);
$GLOBALS['pme_context'] = (object) ['companyId' => str_repeat('b', 32), 'organizationId' => 'org-b', 'prefix' => 'b_'];
$scoped = nfse_nacional_scope();
check($scoped !== $scopeB, 'runtime Multiempresa acrescenta identidade empresarial');
$GLOBALS['pme_context']->prefix = 'a_';
$mismatchDenied = false;
try { nfse_nacional_scope(); } catch (RuntimeException $expected) { $mismatchDenied = true; }
check($mismatchDenied, 'prefixo divergente do runtime bloqueia emissão');
unset($GLOBALS['pme_context']);
$missingDenied = false;
try { nfse_nacional_scope(); } catch (RuntimeException $expected) { $missingDenied = true; }
check($missingDenied, 'contexto Multiempresa ausente não faz fallback à instalação principal');

echo 'OK: ' . $integrationChecks . " verificações de autorização e isolamento.\n";
