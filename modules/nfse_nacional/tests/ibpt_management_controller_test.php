<?php
declare(strict_types=1);

namespace NfseNacional {
    /** Transport/service boundary only; the real controller and UF resolver are exercised below. */
    final class IbptTable
    {
        public static int $currentCalls = 0;
        public static array $refreshCalls = [];
        public static array $allowOlderCalls = [];
        public static array $result = [];
        public static function current(): self { ++self::$currentCalls; return new self(); }
        public function refresh(string $uf, bool $allowOlder = false): array { self::$refreshCalls[] = $uf; self::$allowOlderCalls[] = $allowOlder; return self::$result + ['uf' => $uf]; }
    }
}

namespace {
    // Offline route contract. Perfex session middleware and CSRF token verification
    // are outside this fixture; no CRM DB, certificate, HTTP, SMTP or fiscal write.
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    define('BASEPATH', __DIR__ . '/');

    final class IbptManagementInput
    {
        public string $verb = 'POST';
        public array $posted = ['expected_uf' => 'BA'];
        public function method(): string { return strtolower($this->verb); }
        public function post(string $name, bool $filter = true): mixed { return $this->posted[$name] ?? null; }
    }
    final class IbptManagementOutput
    {
        public int $status = 200;
        public string $body = '';
        public array $headers = [];
        public function set_status_header(int $status): self { $this->status = $status; return $this; }
        public function set_header(string $header): self { $this->headers[] = $header; return $this; }
        public function set_content_type(string $mime, string $charset = ''): self { $this->headers[] = $mime . ';' . $charset; return $this; }
        public function set_output(string $body): self { $this->body = $body; return $this; }
    }
    final class IbptManagementConfig
    {
        public bool $csrf = true;
        public function item(string $name): mixed { return $name === 'csrf_protection' ? $this->csrf : null; }
    }
    final class IbptManagementSecurity
    {
        public function get_csrf_token_name(): string { return 'csrf_ibpt_fixture'; }
        public function get_csrf_hash(): string { return 'csrf-rotated-offline'; }
    }
    final class IbptManagementModel
    {
        public int $reads = 0;
        public array $provider = ['state' => 'BA', 'municipality_code' => '2928703'];
        public string $allowOlder = '0';
        public function settings(): array { ++$this->reads; return ['provider' => $this->provider, 'ibpt_allow_older_table' => $this->allowOlder]; }
    }
    class AdminController
    {
        public IbptManagementInput $input;
        public IbptManagementOutput $output;
        public IbptManagementConfig $config;
        public IbptManagementSecurity $security;
        public function __construct() { throw new \LogicException('No real CRM bootstrap in this offline fixture.'); }
    }
    $ibptManagementAccess = ['logged' => true, 'permissions' => ['view', 'settings']];
    function is_staff_logged_in(): bool { return $GLOBALS['ibptManagementAccess']['logged']; }
    function is_admin(): bool { return false; }
    function staff_can(string $action, string $feature): bool
    {
        return $feature === 'nfse_nacional' && in_array($action, $GLOBALS['ibptManagementAccess']['permissions'], true);
    }
    function admin_url(string $path = ''): string { return 'https://crm.example.invalid/company-a/admin/' . $path; }
    function log_message($level, $message): void {}

    require_once dirname(__DIR__) . '/controllers/Nfse_nacional.php';

    $ibptManagementChecks = 0;
    function ibptManagementCheck(bool $condition, string $message): void
    {
        if (!$condition) { throw new \RuntimeException('FALHOU: ' . $message); }
        ++$GLOBALS['ibptManagementChecks'];
    }
    function ibptManagementController(): array
    {
        $controller = (new \ReflectionClass(\Nfse_nacional::class))->newInstanceWithoutConstructor();
        $controller->input = new IbptManagementInput();
        $controller->output = new IbptManagementOutput();
        $controller->config = new IbptManagementConfig();
        $controller->security = new IbptManagementSecurity();
        $model = new IbptManagementModel();
        (new \ReflectionProperty(\Nfse_nacional::class, 'fiscal'))->setValue($controller, $model);
        \NfseNacional\IbptTable::$currentCalls = 0;
        \NfseNacional\IbptTable::$refreshCalls = [];
        \NfseNacional\IbptTable::$allowOlderCalls = [];
        \NfseNacional\IbptTable::$result = [
            'status' => 'current', 'status_label' => 'Vigente', 'version' => '26.2.C', 'key' => 'TEST72',
            'source' => 'IBPT', 'valid_from' => '2026-11-01', 'valid_to' => '2026-12-31',
            'fetched_at' => '2026-11-11T12:00:00Z', 'service_count' => 860, 'can_refresh' => false,
            'message' => 'Tabela vigente.', 'refresh_state' => 'updated', 'refresh_message' => 'Tabela atualizada.',
        ];
        $GLOBALS['ibptManagementAccess'] = ['logged' => true, 'permissions' => ['view', 'settings']];
        return [$controller, $model];
    }
    function ibptManagementResponse(\Nfse_nacional $controller): array
    {
        return json_decode($controller->output->body, true, 32, JSON_THROW_ON_ERROR);
    }
    function ibptManagementNoDownload(string $label): void
    {
        ibptManagementCheck(\NfseNacional\IbptTable::$currentCalls === 0 && \NfseNacional\IbptTable::$refreshCalls === [], $label);
    }

    foreach (['GET', 'HEAD', 'DELETE'] as $verb) {
        [$controller, $model] = ibptManagementController(); $controller->input->verb = $verb;
        $controller->ibpt_refresh();
        ibptManagementCheck($controller->output->status === 405 && !ibptManagementResponse($controller)['ok'], $verb . ' não atualiza tabela');
        ibptManagementCheck($model->reads === 0, $verb . ' é recusado antes de ler parâmetros da empresa');
        ibptManagementNoDownload($verb . ' não abre o serviço nem aciona transporte');
    }
    foreach ([['logged' => false, 'permissions' => ['view', 'settings']], ['logged' => true, 'permissions' => ['view']], ['logged' => true, 'permissions' => ['settings']]] as $access) {
        [$controller, $model] = ibptManagementController(); $ibptManagementAccess = $access;
        $controller->ibpt_refresh();
        ibptManagementCheck($controller->output->status === 403 && !ibptManagementResponse($controller)['ok'], 'sessão e ambas permissões são exigidas');
        ibptManagementCheck($model->reads === 0, 'negação de acesso antecede leitura do emitente');
        ibptManagementNoDownload('negação de acesso não abre serviço');
    }
    [$controller, $model] = ibptManagementController(); $controller->config->csrf = false;
    $controller->ibpt_refresh();
    ibptManagementCheck($controller->output->status === 503 && $model->reads === 0, 'proteção CSRF desativada impede atualização antes do modelo');
    ibptManagementNoDownload('proteção CSRF desativada não abre serviço');

    foreach ([null, '', 'SP', 'ba', ['BA']] as $expected) {
        [$controller, $model] = ibptManagementController(); $controller->input->posted['expected_uf'] = $expected;
        $controller->ibpt_refresh();
        ibptManagementCheck($controller->output->status === 409 && !ibptManagementResponse($controller)['ok'], 'UF esperada ausente, divergente ou inválida impede atualização');
        ibptManagementNoDownload('UF divergente não escolhe outra tabela nem abre transporte');
    }
    foreach ([['state' => '', 'municipality_code' => ''], ['state' => 'BA', 'municipality_code' => '3550308'], ['state' => 'ZZ', 'municipality_code' => '2928703']] as $provider) {
        [$controller, $model] = ibptManagementController(); $model->provider = $provider;
        $controller->ibpt_refresh();
        ibptManagementCheck($controller->output->status === 422 && !ibptManagementResponse($controller)['ok'], 'emitente ausente ou inconsistente é recusado pelo resolvedor real de UF');
        ibptManagementNoDownload('UF inválida do cadastro não alcança transporte');
    }

    [$controller, $model] = ibptManagementController();
    $controller->input->posted += ['uf' => 'SP', 'nbs' => '', 'company_id' => 'outra-empresa', 'settings' => ['provider' => ['state' => 'SP']]];
    $controller->ibpt_refresh(); $result = ibptManagementResponse($controller);
    ibptManagementCheck($controller->output->status === 200 && $result['ok'] === true, 'atualização autorizada funciona sem NBS');
    ibptManagementCheck($model->reads === 1 && \NfseNacional\IbptTable::$currentCalls === 1 && \NfseNacional\IbptTable::$refreshCalls === ['BA'], 'UF vem somente dos parâmetros salvos da empresa ativa');
    ibptManagementCheck(($result['data']['ibpt_table']['key'] ?? '') === 'TEST72' && ($result['data']['ibpt_table']['valid_to'] ?? '') === '2026-12-31', 'retorno conserva chave e vigência para o painel');
    ibptManagementCheck($result['csrf'] === ['name' => 'csrf_ibpt_fixture', 'hash' => 'csrf-rotated-offline'], 'resposta renova CSRF para a próxima ação sem recarregar formulário');
    ibptManagementCheck(!array_key_exists('reload', $result['data']) && !array_key_exists('url', $result['data']), 'atualização não exige navegação nem gravação das configurações');

    [$controller, $model] = ibptManagementController(); $model->provider['state'] = '';
    $controller->ibpt_refresh();
    ibptManagementCheck($controller->output->status === 200 && \NfseNacional\IbptTable::$refreshCalls === ['BA'], 'UF pode ser derivada do IBGE salvo do emitente');

    [$controller, $model] = ibptManagementController();
    \NfseNacional\IbptTable::$result = array_replace(\NfseNacional\IbptTable::$result, ['status' => 'expired', 'valid_to' => '2026-10-31', 'refresh_state' => 'failed', 'refresh_message' => 'A fonte retornou tabela vencida.']);
    $controller->ibpt_refresh(); $result = ibptManagementResponse($controller);
    ibptManagementCheck(($result['data']['ibpt_table']['status'] ?? '') === 'expired' && $result['message'] === 'A fonte retornou tabela vencida.', 'resposta não converte download vencido em situação vigente');

    [$controller, $model] = ibptManagementController();
    $controller->input->posted['settings'] = ['ibpt_allow_older_table' => '1'];
    $controller->ibpt_refresh();
    ibptManagementCheck(\NfseNacional\IbptTable::$allowOlderCalls === [false],
        'unsaved POST option cannot authorize an older API refresh');

    [$controller, $model] = ibptManagementController();
    $model->allowOlder = '1';
    $controller->ibpt_refresh();
    ibptManagementCheck(\NfseNacional\IbptTable::$allowOlderCalls === [true],
        'saved company option explicitly authorizes an older API refresh');

    echo 'OK: ' . $ibptManagementChecks . " verificações do gerenciamento IBPT no controller.\n";
}
