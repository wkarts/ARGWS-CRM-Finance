<?php
declare(strict_types=1);

// Native-integration contract tests. No Perfex source, network, fiscal writes or SMTP.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__ . '/');

final class HooksRegistry
{
    public array $actions = [];
    public array $filters = [];
    public function add_action($name, $callback, $priority = 10, $accepted = 1): void { $this->actions[$name][] = [$callback, $priority, $accepted]; }
    public function add_filter($name, $callback, $priority = 10, $accepted = 1): void { $this->filters[$name][] = [$callback, $priority, $accepted]; }
    public function run(string $name, $value = null): void { foreach ($this->actions[$name] ?? [] as $action) { ($action[0])($value); } }
    public function apply_filters(string $name, $value, ...$args) { foreach ($this->filters[$name] ?? [] as $filter) { $value = ($filter[0])(...array_slice([$value, ...$args], 0, $filter[2])); } return $value; }
}
final class HooksResult
{
    public function __construct(private array $rows) {}
    public function row_array(): array { return $this->rows[0] ?? []; }
}
final class HooksDatabase
{
    public string $database = 'tenant_a';
    public string $hostname = 'localhost';
    public array $tables = [];
    private array $where = [];
    public function select($columns): self { return $this; }
    public function where($column, $value): self { $this->where[$column] = $value; return $this; }
    public function limit($limit): self { return $this; }
    public function table_exists($table): bool { return isset($this->tables[$table]); }
    public function get($table): HooksResult
    {
        $where = $this->where; $this->where = [];
        return new HooksResult(array_values(array_filter($this->tables[$table] ?? [], static function ($row) use ($where): bool {
            foreach ($where as $column => $value) { if ((string) ($row[$column] ?? '') !== (string) $value) { return false; } }
            return true;
        })));
    }
}
final class HooksMenu
{
    public array $items = [];
    public array $children = [];
    public function add_sidebar_menu_item($slug, $data): void { $this->items[$slug] = $data; }
    public function add_sidebar_children_item($slug, $data): void { $this->children[] = [$slug, $data]; }
}
final class HooksTabs
{
    public array $settings = [];
    public array $customers = [];
    public function add_settings_tab($slug, $data): void { $this->settings[$slug] = $data; }
    public function add_customer_profile_tab($slug, $data): void { $this->customers[$slug] = $data; }
}
final class HooksApp
{
    public array $sections = [];
    public function add_settings_section($slug, $data): void { $this->sections[$slug] = $data; }
}
final class HooksModel
{
    public function settings(): array { return ['variable_simple_rate' => '0', 'automation' => ['mode' => 'disabled']]; }
    public $db;
    public function __construct() { $this->db = new class { public function __call($method, $args) { return false; } }; }
    public array $context = [];
    public array $calls = [];
    public int $settingsCalls = 0;
    public array $recipientValidated = [];
    public array $recipientSaved = [];
    public array $recipientSynced = [];
    public function invoiceContext(int $id): array { $this->calls[] = $id; return $this->context; }
    public function settingsData(): array { ++$this->settingsCalls; return ['settings' => ['environment' => '2']]; }
    public function validateInvoiceRecipientSelection(int $clientId, string $selection): string
    {
        $this->recipientValidated[] = [$clientId, $selection];
        if (!in_array($selection, ['', 'client', 'primary_contact', 'recipient:2'], true)) { throw new InvalidArgumentException('Tomador inválido.'); }
        return $selection;
    }
    public function saveInvoiceRecipientSelection(int $id, int $clientId, string $selection): void { $this->recipientSaved[] = [$id, $clientId, $selection]; }
    public function syncInvoiceRecipientClient(int $id): void { $this->recipientSynced[] = $id; }
}
final class HooksLoader
{
    public array $views = [];
    public function view(string $name, array $data = []): void
    {
        $this->views[] = $name;
        if ($name === 'nfse_nacional/settings') { echo '<div data-native-settings-partial="1"></div>'; return; }
        extract($data, EXTR_SKIP);
        require dirname(__DIR__) . '/views/' . basename($name) . '.php';
    }
}
final class HooksSecurity
{
    public function get_csrf_token_name(): string { return 'csrf_test'; }
    public function get_csrf_hash(): string { return 'csrf-hash-offline'; }
}
final class HooksUri { public function uri_string(): string { return $GLOBALS['hooksState']['uri']; } }
final class HooksInput
{
    public function get($name) { return $GLOBALS['hooksState']['query'][$name] ?? null; }
    public function method(): string { return $GLOBALS['hooksState']['method'] ?? 'GET'; }
}

$hooksState = ['logged' => true, 'admin' => false, 'prefix' => 'a_', 'uri' => 'admin/invoices/list_invoices/101', 'query' => [],
    'permissions' => ['nfse_nacional' => ['view', 'create', 'edit', 'transmit', 'settings', 'report_crm'], 'customers' => ['view'], 'invoices' => ['view'], 'settings' => ['view']]];
$hooksRegistry = new HooksRegistry();
$hooksCi = (object) ['db' => new HooksDatabase(), 'app' => new HooksApp(), 'app_menu' => new HooksMenu(), 'app_tabs' => new HooksTabs(),
    'nfse_nacional_model' => new HooksModel(), 'load' => new HooksLoader(), 'security' => new HooksSecurity(), 'uri' => new HooksUri(), 'input' => new HooksInput()];
$hooksCi->db->tables = [
    'a_clients' => [['userid' => 10]],
    'a_invoices' => [['id' => 101, 'clientid' => 10, 'status' => 1, 'addedfrom' => 7]],
    'b_clients' => [['userid' => 20]],
    'b_invoices' => [['id' => 201, 'clientid' => 20, 'status' => 1, 'addedfrom' => 7]],
];
function &get_instance() { return $GLOBALS['hooksCi']; }
function hooks(): HooksRegistry { return $GLOBALS['hooksRegistry']; }
function register_activation_hook($module, $callback): void {}
function register_uninstall_hook($module, $callback): void {}
function register_language_files($module, $files): void {}
function register_staff_capabilities($feature, $caps, $name): void { $GLOBALS['registered_capabilities'][$feature] = $caps; }
function is_staff_logged_in(): bool { return $GLOBALS['hooksState']['logged']; }
function is_admin(): bool { return $GLOBALS['hooksState']['admin']; }
function get_staff_user_id(): int { return 7; }
function staff_can($action, $feature): bool { return in_array($action, $GLOBALS['hooksState']['permissions'][$feature] ?? [], true); }
function get_option($key): string { return ''; }
function db_prefix(): string { return $GLOBALS['hooksState']['prefix']; }
function admin_url($path = ''): string { return ($GLOBALS['hooksState']['admin_base'] ?? 'https://crm.example.invalid/admin/') . $path; }
function redirect($url, $method = 'auto', $code = null): void { $GLOBALS['hooksState']['redirects'][] = [$url, $method, $code]; }
function module_dir_url($module, $path): string { return 'https://crm.example.invalid/modules/' . $module . '/' . $path; }
function log_message($level, $message): void {}
require dirname(__DIR__) . '/nfse_nacional.php';

$hookChecks = 0;
function hookCheck(bool $value, string $label): void
{
    if (!$value) { throw new RuntimeException('FALHOU: ' . $label); }
    ++$GLOBALS['hookChecks'];
}
function hookRefuses(callable $operation, string $label): void
{
    try { $operation(); } catch (RuntimeException $expected) { hookCheck(true, $label); return; }
    throw new RuntimeException('FALHOU: ' . $label);
}
function hookOutput(callable $callback): string { ob_start(); try { $callback(); return (string) ob_get_contents(); } finally { ob_end_clean(); } }
function hookPanel(array $documents, string $environment = '1', ?array $capabilities = null, int $invoiceStatus = 1, bool $historyConflict = false): string
{
    $ci = get_instance();
    $ci->nfse_nacional_model->context = [
        'invoice' => (object) ['id' => 101, 'clientid' => 10, 'status' => $invoiceStatus],
        'documents' => $documents, 'environment' => $environment,
        'history_conflict' => $historyConflict,
        'can' => $capabilities ?? ['view' => true, 'create' => true, 'edit' => true, 'transmit' => true],
    ];
    return hookOutput(static fn() => hooks()->run('after_invoice_preview_template_rendered', (object) ['id' => 101, 'clientid' => 999]));
}

hookCheck(isset($hooksRegistry->actions['after_invoice_preview_template_rendered'], $hooksRegistry->actions['after_invoice_view_as_client_link']), 'painel e atalho usam hooks administrativos identificados');
hookCheck(!isset($hooksRegistry->actions['after_total_summary_invoicehtml']), 'nenhum controle fiscal registrado no HTML público da fatura');
$existingMenus = ['sales' => ['name' => 'Vendas', 'collapse' => true, 'icon' => 'fa fa-bolt'], 'finance' => ['name' => 'Financeiro', 'icon' => 'fa fa-bank']];
$hooksCi->app_menu->items = $existingMenus;
$hooksCi->app_tabs->customers['profile'] = ['name' => 'Perfil', 'icon' => 'fa fa-user'];
$existingProfileTab = $hooksCi->app_tabs->customers['profile'];
hooks()->run('admin_init');
hookCheck(isset($GLOBALS['registered_capabilities']['nfse_nacional']['capabilities']['rates_view'], $GLOBALS['registered_capabilities']['nfse_nacional']['capabilities']['rates_edit'], $GLOBALS['registered_capabilities']['nfse_nacional']['capabilities']['report_received']), 'alíquotas e relatórios registrados nas permissões nativas de equipe e funções');
$section = $hooksCi->app->sections['documentos_fiscais'] ?? [];
hookCheck(($section['slug'] ?? '') === 'documentos_fiscais' && ($section['title'] ?? '') === 'Documentos Fiscais' && ($section['children'][0]['slug'] ?? '') === 'nfse_nacional' && ($section['children'][0]['view'] ?? '') === 'nfse_nacional/nfse_nacional', 'seção moderna registra seção, grupo e wrapper nativos');
// The authenticated portal rendered this section with group=basename(view),
// ignoring the explicitly supplied slug. This assertion covers that observed
// navigation contract; this fixture does not claim to reproduce Perfex internals.
$observedSettingsGroup = basename((string) ($section['children'][0]['view'] ?? ''));
hookCheck($observedSettingsGroup === ($section['children'][0]['slug'] ?? null)
    && nfse_nacional_settings_url() === admin_url('settings?group=' . $observedSettingsGroup),
    'basename da view, slug e atalho concordam com a navegação observada no portal');
hookCheck($hooksCi->app_tabs->settings === [], 'API legada não é chamada quando seção moderna existe');
$fiscalMenu = $hooksCi->app_menu->items['nfse-nacional'] ?? [];
hookCheck(($fiscalMenu['name'] ?? '') === 'NFSE' && ($fiscalMenu['collapse'] ?? false) === true, 'menu principal NFSE usa expansão nativa');
$fiscalChildren = array_values(array_filter($hooksCi->app_menu->children, static fn($child) => $child[0] === 'nfse-nacional'));
hookCheck(count($fiscalChildren) === 2 && ($fiscalChildren[0][1]['slug'] ?? '') === 'nfse-nacional-documentos'
    && ($fiscalChildren[0][1]['name'] ?? '') === 'Documentos' && ($fiscalChildren[0][1]['href'] ?? '') === admin_url('nfse_nacional'),
    'único submenu Documentos abre a gestão fiscal pela rota administrativa atual');
hookCheck(empty($fiscalChildren[0][1]['icon']) && count($hooksCi->app_menu->children) === 2,
    'submenu Documentos não recebe ícone e não existe Configurações no menu operacional');
$savedMenuChildren = $hooksCi->app_menu->children;
$hooksCi->app_menu->children = [];
$hooksState['permissions']['nfse_nacional'][] = 'logs';
hooks()->run('admin_init');
$logChildren = array_values(array_filter($hooksCi->app_menu->children, static fn($child) => $child[0] === 'nfse-nacional'));
hookCheck(count($logChildren) === 3 && ($logChildren[2][1]['name'] ?? '') === 'Logs'
    && ($logChildren[2][1]['href'] ?? '') === admin_url('nfse_nacional/logs') && empty($logChildren[2][1]['icon']),
    'capacidade de logs habilita somente o submenu Logs, sem ícone adicional ou Configurações');
$hooksState['permissions']['nfse_nacional'] = ['logs'];
hookCheck(!nfse_nacional_can('logs'), 'logs também exige permissão fiscal de visualização');
$hooksState['permissions']['nfse_nacional'] = ['view', 'create', 'edit', 'transmit', 'settings'];
hookCheck(!nfse_nacional_can('logs'), 'configurar a emissão não concede leitura dos diagnósticos');
$hooksCi->app_menu->children = $savedMenuChildren;
hookCheck(array_intersect_key($hooksCi->app_menu->items, $existingMenus) === $existingMenus
    && $hooksCi->app_tabs->customers['profile'] === $existingProfileTab,
    'registro fiscal preserva menus e guia do cliente que já pertencem à aplicação');
hookCheck(($hooksCi->app_tabs->customers['nfse_nacional']['icon'] ?? '') === 'fa-solid fa-file-invoice' && ($hooksCi->app_menu->items['nfse-nacional']['icon'] ?? '') === 'fa-solid fa-file-invoice', 'menu e aba cliente usam ícone Font Awesome compatível com o padrão do CRM');
$modernApp = $hooksCi->app;
$hooksCi->app = new stdClass();
hookCheck(nfse_nacional_register_settings() && ($hooksCi->app_tabs->settings['nfse_nacional']['view'] ?? '') === 'nfse_nacional/nfse_nacional', 'API legada preserva o mesmo grupo quando seções não existem');
$hooksCi->app = $modernApp;
$hooksState['permissions']['settings'] = [];
hookCheck(!nfse_nacional_settings_allowed() && !nfse_nacional_register_settings(), 'capacidade fiscal não concede acesso nativo às Configurações');
$callsBefore = $hooksCi->nfse_nacional_model->settingsCalls;
$blockedSettings = hookOutput(static fn() => get_instance()->load->view('nfse_nacional/settings_group'));
hookCheck($hooksCi->nfse_nacional_model->settingsCalls === $callsBefore && !str_contains($blockedSettings, 'data-native-settings-partial'), 'wrapper bloqueado não carrega configurações/certificado');
$hooksState['permissions']['settings'] = ['view'];
$settingsHtml = hookOutput(static fn() => get_instance()->load->view('nfse_nacional/settings_group'));
hookCheck(str_contains($settingsHtml, 'data-native-settings-partial') && !str_contains($settingsHtml, '<form'), 'wrapper nativo não cria formulário aninhado');
$canonicalSettingsHtml = hookOutput(static fn() => get_instance()->load->view('nfse_nacional/nfse_nacional'));
hookCheck($canonicalSettingsHtml === $settingsHtml, 'wrapper com basename canônico preserva o mesmo conteúdo e a proteção do wrapper antigo');
hookCheck(nfse_nacional_settings_url() === admin_url('settings?group=nfse_nacional'), 'link de configuração aponta ao grupo nativo');
$legacySavedState = $hooksState;
foreach ([['GET', 'admin/settings', 'https://crm.example.invalid/admin/'], ['HEAD', 'company-hash/admin/settings', 'https://crm.example.invalid/company-hash/admin/']] as [$method, $uri, $base]) {
    $hooksState = array_replace($legacySavedState, ['method' => $method, 'uri' => $uri, 'admin_base' => $base, 'query' => ['group' => 'settings_group'], 'redirects' => []]);
    hookCheck(nfse_nacional_redirect_legacy_settings()
        && $hooksState['redirects'] === [[$base . 'settings?group=nfse_nacional', 'location', 302]],
        'link antigo em ' . $method . ' redireciona ao grupo canônico preservando a rota da empresa');
}
foreach ([
    ['POST', 'admin/settings', 'settings_group'],
    ['GET', 'admin/dashboard', 'settings_group'],
    ['GET', 'admin/settings', 'nfse_nacional'],
] as [$method, $uri, $group]) {
    $hooksState = array_replace($legacySavedState, ['method' => $method, 'uri' => $uri, 'query' => ['group' => $group], 'redirects' => []]);
    hookCheck(!nfse_nacional_redirect_legacy_settings() && $hooksState['redirects'] === [],
        'redirecionamento não intercepta ' . $method . ' ' . $uri . '?group=' . $group);
}
foreach (['fiscal', 'native', 'anonymous'] as $boundary) {
    $hooksState = array_replace($legacySavedState, ['method' => 'GET', 'uri' => 'admin/settings', 'query' => ['group' => 'settings_group'], 'redirects' => []]);
    if ($boundary === 'fiscal') { $hooksState['permissions']['nfse_nacional'] = ['view']; }
    elseif ($boundary === 'native') { $hooksState['permissions']['settings'] = []; }
    else { $hooksState['logged'] = false; }
    hookCheck(!nfse_nacional_redirect_legacy_settings() && $hooksState['redirects'] === [],
        'link antigo preserva a fronteira de acesso: ' . $boundary);
}
$hooksState = array_replace($legacySavedState, ['method' => 'GET', 'uri' => 'admin/settings', 'query' => ['group' => 'settings_group'], 'redirects' => []]);
$legacySections = $hooksCi->app->sections; $legacyChildren = $hooksCi->app_menu->children;
$hooksCi->app->sections = [];
hooks()->run('admin_init');
hookCheck(count($hooksState['redirects']) === 1 && $hooksCi->app->sections === [] && $hooksCi->app_menu->children === $legacyChildren,
    'admin_init interrompe antes do registro de menus ao encaminhar o endereço antigo');
$hooksCi->app->sections = $legacySections;
hookCheck(count($legacySections['documentos_fiscais']['children']) === 1 && !isset($hooksCi->app_tabs->settings['settings_group']),
    'compatibilidade do link antigo não cria uma segunda aba de configurações');
$hooksState = $legacySavedState;
hookCheck(nfse_nacional_is_screen(), 'assets carregam na tela Vendas/Faturas antes de previews AJAX');
$savedUri = $hooksState['uri'];
foreach ([
    ['company-hash/admin/dashboard', []],
    ['company-hash/admin/settings', ['group' => 'general']],
    ['company-hash/admin/dashboard', ['group' => 'settings_group']],
] as [$uri, $query]) {
    $hooksState['uri'] = $uri; $hooksState['query'] = $query;
    hookCheck(hookOutput('nfse_nacional_admin_head') === '' && hookOutput('nfse_nacional_admin_footer') === '',
        'navegação nativa dispensa CSS e JavaScript fiscais em ' . $uri . '?' . http_build_query($query));
}
foreach ([
    ['company-hash/admin/nfse_nacional', []],
    ['company-hash/admin/nfse_nacional/document/123', []],
    ['company-hash/admin/invoices', []],
    ['company-hash/admin/invoices/list_invoices/101', []],
    ['company-hash/admin/clients/client/10', ['group' => 'profile']],
    ['company-hash/admin/clients/client/10', ['group' => 'contacts']],
    ['company-hash/admin/clients/client/10', ['group' => 'nfse_nacional']],
    ['company-hash/admin/settings', ['group' => 'nfse_nacional']],
    ['admin/settings', ['group' => 'settings_group']],
    ['company-hash/admin/settings', ['group' => 'settings_group']],
] as [$uri, $query]) {
    $hooksState['uri'] = $uri; $hooksState['query'] = $query;
    $head = hookOutput('nfse_nacional_admin_head'); $footer = hookOutput('nfse_nacional_admin_footer');
    hookCheck(str_contains($head, '/nfse_nacional/asset/style?v=') && str_contains($footer, '/nfse_nacional/asset/script?v=')
        && !str_contains($head . $footer, 'navigation-icons') && !str_contains($head . $footer, '/modules/'),
        'tela fiscal recebe somente seus assets da cópia da empresa: ' . $uri . '?' . http_build_query($query));
}
$hooksState['logged'] = false;
hookCheck(hookOutput('nfse_nacional_admin_head') === '' && hookOutput('nfse_nacional_admin_footer') === '', 'sessão sem acesso não recebe assets fiscais');
$hooksState['logged'] = true; $hooksState['uri'] = $savedUri; $hooksState['query'] = [];

// Exercise the server-side native DataTable filter, including redraw and scope.
hookCheck(($hooksRegistry->filters['invoices_table_row_data'][0][2] ?? null) === 2, 'filtro da grade recebe célula e registro SQL separadamente');
$nativeCells = ['<a href="' . admin_url('invoices/list_invoices/101') . '" onclick="init_invoice(101); return false;">FAT-101</a><div class="row-options"><a href="#">Ver</a></div>', 'R$ 250,00', 'Cliente', 'DT_RowClass' => 'invoice-row'];
$rawInvoice = ['id' => 101, 'clientid' => 999, 'status' => 1];
$grid = hooks()->apply_filters('invoices_table_row_data', $nativeCells, $rawInvoice);
hookCheck(count($grid) === count($nativeCells) && $grid[1] === $nativeCells[1] && $grid['DT_RowClass'] === 'invoice-row', 'ação preserva colunas, ordenação e metadados da grade');
hookCheck(str_contains($grid[0], 'data-nfse-grid-action="101"') && str_contains($grid[0], '> Emitir NFS-e</a>') && str_contains($grid[0], 'invoice_context/101'), 'cada linha elegível contém ação com ID da própria fatura');
hookCheck(str_contains($grid[0], '</div><div class="nfse-invoice-grid-action-wrap">') && !str_contains($grid[0], 'emit_invoice/'), 'botão é visível fora row-options e abrir painel não transmite');
hookCheck(hooks()->apply_filters('invoices_table_row_data', $grid, $rawInvoice) === $grid, 'filtro repetido não duplica o botão');
$selected = ['<input type="checkbox" value="101">', $nativeCells[0], 'R$ 250,00'];
$selectedGrid = hooks()->apply_filters('invoices_table_row_data', $selected, $rawInvoice);
hookCheck($selectedGrid[0] === $selected[0] && str_contains($selectedGrid[1], 'data-nfse-grid-action'), 'tema com seleção de linhas mantém checkbox e ação na célula da fatura');
hookCheck(hooks()->apply_filters('invoices_table_row_data', $nativeCells, ['id' => 201]) === $nativeCells, 'linha estrangeira não obtém ação usando ID de outra empresa');
hookCheck(hooks()->apply_filters('invoices_table_row_data', $nativeCells, ['id' => '101 OR 1=1']) === $nativeCells, 'ID malformado da grade é recusado');
$cancelled = $rawInvoice; $cancelled['status'] = 5;
$cancelledGrid = hooks()->apply_filters('invoices_table_row_data', $nativeCells, $cancelled);
hookCheck(str_contains($cancelledGrid[0], '> Consultar NFS-e</a>') && !str_contains($cancelledGrid[0], '> Emitir NFS-e</a>'), 'fatura cancelada oferece consulta em vez de nova emissão');
$savedFiscalPermissions = $hooksState['permissions']['nfse_nacional'];
$hooksState['permissions']['nfse_nacional'] = ['view'];
$readGrid = hooks()->apply_filters('invoices_table_row_data', $nativeCells, $rawInvoice);
hookCheck(str_contains($readGrid[0], '> Consultar NFS-e</a>'), 'visualizador da grade abre somente contexto de consulta');
$hooksState['permissions']['nfse_nacional'] = [];
hookCheck(hooks()->apply_filters('invoices_table_row_data', $nativeCells, $rawInvoice) === $nativeCells, 'sem permissão fiscal a grade nativa permanece intacta');
$hooksState['permissions']['nfse_nacional'] = $savedFiscalPermissions;

$empty = hookPanel([]);
hookCheck(str_contains($empty, 'emit_invoice/101') && str_contains($empty, '> Emitir NFS-e</button>'), 'ação direta de emissão aparece na fatura sem nota');
hookCheck(str_contains($empty, 'prepare/101') && str_contains($empty, 'data-csrf-name="csrf_test"') && str_contains($empty, 'data-csrf-hash="csrf-hash-offline"'), 'rascunho e mutações carregam token CSRF nativo');
hookCheck(!preg_match('~<a[^>]+href="[^"]*(?:emit_invoice|prepare|transmit|reconcile)/~', $empty), 'nenhuma mutação fiscal é link GET');
$testDoc = ['id' => 901, 'invoice_id' => 101, 'client_id' => 10, 'environment' => '2', 'status' => 'authorized', 'nfse_number' => '123'];
$prodDoc = ['id' => 902, 'invoice_id' => 101, 'client_id' => 10, 'environment' => '1', 'status' => 'authorized', 'nfse_number' => '124'];
$testOnly = hookPanel([$testDoc]);
hookCheck(str_contains($testOnly, 'document/901') && str_contains($testOnly, 'emit_invoice/101') && str_contains($testOnly, 'Sem validade fiscal'), 'nota de testes permanece visível e não bloqueia primeira emissão em produção');
$both = hookPanel([$prodDoc, $testDoc]);
hookCheck(str_contains($both, 'document/901') && str_contains($both, 'document/902') && !str_contains($both, 'emit_invoice/101'), 'histórico dos dois ambientes permanece e nota atual impede nova preparação');
$draft = $prodDoc; $draft['status'] = 'draft'; $draft['nfse_number'] = null;
$draftHtml = hookPanel([$draft, $testDoc]);
hookCheck(str_contains($draftHtml, 'Continuar rascunho') && str_contains($draftHtml, 'transmit/902') && !str_contains($draftHtml, 'prepare/101'), 'rascunho existente continua a mesma emissão');
$cancelledPanel = hookPanel([$draft], '1', null, 5);
hookCheck(!str_contains($cancelledPanel, 'transmit/902') && str_contains($cancelledPanel, 'document/902'), 'cancelamento da fatura mantém leitura e impede botão de transmitir rascunho anterior');
$conflictPanel = hookPanel([], '1', null, 1, true);
hookCheck(!str_contains($conflictPanel, 'emit_invoice/101') && !str_contains($conflictPanel, 'prepare/101'), 'painel com conflito do cliente bloqueia emissão mesmo com capacidades fornecidas por engano');
$hooksState['permissions']['nfse_nacional'] = ['view'];
$readOnly = hookPanel([$draft]);
hookCheck(!str_contains($readOnly, 'transmit/902') && str_contains($readOnly, 'document/902'), 'usuário com visualização não recebe ação de transmissão');
$pending = $prodDoc; $pending['status'] = 'uncertain'; $pending['nfse_number'] = null;
$pendingHtml = hookPanel([$pending], '1', ['view' => true]);
hookCheck(str_contains($pendingHtml, 'reconcile/902') && !str_contains($pendingHtml, 'transmit/902'), 'consulta de pendência é acessível com visualização, sem transmitir');
$evil = $prodDoc; $evil['nfse_number'] = '<script>alert(1)</script>';
$escaped = hookPanel([$evil]);
hookCheck(!str_contains($escaped, '<script>') && str_contains($escaped, '&lt;script&gt;'), 'texto recebido do documento é escapado');
$foreign = $prodDoc; $foreign['id'] = 999; $foreign['client_id'] = 20;
hookCheck(!str_contains(hookPanel([$foreign]), 'document/999'), 'contexto não exibe documento de outro cliente');
$shortcut = hookOutput(static fn() => hooks()->run('after_invoice_view_as_client_link', (object) ['id' => 101]));
hookCheck(str_contains($shortcut, 'data-nfse-open-invoice="101"') && str_contains($shortcut, 'invoice_context/101'), 'atalho de compatibilidade consulta somente endpoint privado de leitura');
$before = count($hooksCi->nfse_nacional_model->calls);
foreach ([101, ['id' => 101], (object) ['id' => '101 OR 1=1'], (object) ['id' => '999999999999999999999999']] as $bad) {
    hookCheck(hookOutput(static fn() => hooks()->run('after_invoice_preview_template_rendered', $bad)) === '', 'payload de hook inválido recusado');
}
hookCheck(count($hooksCi->nfse_nacional_model->calls) === $before, 'payload inválido não chega ao modelo fiscal');
$hooksState['permissions']['customers'] = [];
hookCheck(hookPanel([]) === '', 'permissão de faturas não substitui acesso ao cliente');
hookCheck(hooks()->apply_filters('invoices_table_row_data', $nativeCells, $rawInvoice) === $nativeCells, 'filtro da grade também exige acesso ao cliente');
$hooksState['permissions']['customers'] = ['view'];
$hooksState['prefix'] = 'b_'; $hooksCi->db->database = 'tenant_b';
hookCheck(hookPanel([]) === '', 'troca de empresa não carrega ID existente só no banco anterior');
hookCheck(hooks()->apply_filters('invoices_table_row_data', $nativeCells, $rawInvoice) === $nativeCells, 'redraw após trocar empresa não reaproveita acesso da empresa anterior');
$hooksState['logged'] = false;
hookCheck(hookOutput(static fn() => hooks()->run('after_invoice_view_as_client_link', (object) ['id' => 201])) === '', 'atalho nunca aparece em sessão não autenticada');

// Fiscal-only fields must never reach the native invoice INSERT/UPDATE payload.
$hooksState['logged'] = true; $hooksState['prefix'] = 'a_'; $hooksCi->db->database = 'tenant_a';
$hooksState['permissions']['invoices'] = ['view', 'create', 'edit'];
$hooksState['permissions']['nfse_nacional'] = ['view', 'create', 'edit'];
$hooksCi->db->tables['a_nfse_nacional_invoice_preferences'] = [];
$scope = nfse_nacional_scope();
$selectionData = ['clientid' => 10, 'adminnote' => 'Nota interna preservada', 'nfse_nacional_recipient' => 'recipient:2', 'nfse_nacional_recipient_scope' => $scope];
$wrapped = ['data' => $selectionData, 'items' => [['description' => 'Serviço', 'qty' => 1]], 'custom_fields' => ['invoice' => ['1' => 'Valor preservado']]];
$expected = $wrapped; unset($expected['data']['nfse_nacional_recipient'], $expected['data']['nfse_nacional_recipient_scope']);
$filtered = hooks()->apply_filters('before_invoice_added', $wrapped);
hookCheck($filtered === $expected, 'criação remove somente seleção e escopo fiscal, preservando itens e metadados nativos');
hookCheck($hooksCi->nfse_nacional_model->recipientValidated === [[10, 'recipient:2']], 'preferência é validada antes de gravar a fatura nativa');
hooks()->run('after_invoice_added', 101);
hookCheck($hooksCi->nfse_nacional_model->recipientSaved === [[101, 10, 'recipient:2']], 'preferência é associada ao ID real devolvido pela fatura');
hooks()->run('after_invoice_added', 101);
hookCheck(count($hooksCi->nfse_nacional_model->recipientSaved) === 1, 'callback repetida não reaplica uma preferência pendente');
hooks()->apply_filters('before_invoice_added', $wrapped);
$withoutField = ['data' => ['clientid' => 10, 'nfse_nacional_recipient_scope' => $scope], 'items' => $wrapped['items']];
$withoutFiltered = hooks()->apply_filters('before_invoice_added', $withoutField);
hooks()->run('after_invoice_added', 101);
hookCheck(!isset($withoutFiltered['data']['nfse_nacional_recipient_scope']) && count($hooksCi->nfse_nacional_model->recipientSaved) === 1, 'campo ausente não reutiliza preferência de criação anterior e retira escopo isolado');

$updatePayload = ['id' => 101, 'data' => array_replace($selectionData, ['nfse_nacional_recipient' => 'client']), 'items' => $wrapped['items']];
$updateExpected = $updatePayload; unset($updateExpected['data']['nfse_nacional_recipient'], $updateExpected['data']['nfse_nacional_recipient_scope']);
$updateFiltered = nfse_nacional_before_invoice_updated($updatePayload, 101);
hookCheck($updateFiltered === $updateExpected, 'edição mantém ID, itens e demais campos, removendo somente parâmetros fiscais');
$nativeUpdated = false;
nfse_nacional_after_invoice_updated(['id' => 101, 'updated' => &$nativeUpdated]);
hookCheck(end($hooksCi->nfse_nacional_model->recipientSaved) === [101, 10, 'client'] && $nativeUpdated, 'alteração exclusiva do tomador recebe sucesso nativo após persistir preferência');
$savedBeforeAbsent = $hooksCi->nfse_nacional_model->recipientSaved;
$absent = ['id' => 101, 'data' => ['clientid' => 10, 'adminnote' => 'Outra edição'], 'items' => []];
hookCheck(nfse_nacional_before_invoice_updated($absent) === $absent, 'edição sem campo fiscal conserva integralmente o payload nativo');
nfse_nacional_after_invoice_updated(['invoice_id' => 101]);
hookCheck($hooksCi->nfse_nacional_model->recipientSaved === $savedBeforeAbsent && $hooksCi->nfse_nacional_model->recipientSynced === [101], 'campo ausente apenas sincroniza o cliente, sem apagar ou substituir preferência');
$flat = array_replace($selectionData, ['nfse_nacional_recipient' => 'primary_contact']);
$flatExpected = $flat; unset($flatExpected['nfse_nacional_recipient'], $flatExpected['nfse_nacional_recipient_scope']);
hookCheck(nfse_nacional_before_invoice_updated($flat, 101) === $flatExpected, 'formato legado sem wrapper também preserva os campos nativos');
nfse_nacional_after_invoice_updated(101);
hookCheck(end($hooksCi->nfse_nacional_model->recipientSaved) === [101, 10, 'primary_contact'], 'formato legado associa a seleção à mesma fatura');

$badScope = $wrapped; $badScope['data']['nfse_nacional_recipient_scope'] = str_repeat('b', 64);
hookRefuses(static fn() => nfse_nacional_before_invoice_added($badScope), 'formulário de outra empresa é rejeitado antes da escrita nativa');
$badSelection = $wrapped; $badSelection['data']['nfse_nacional_recipient'] = ['recipient:2'];
hookRefuses(static fn() => nfse_nacional_before_invoice_added($badSelection), 'valor fiscal estruturado não contorna validação do hook');
unset($hooksCi->db->tables['a_nfse_nacional_invoice_preferences']);
hookRefuses(static fn() => nfse_nacional_before_invoice_added($wrapped), 'migração ausente impede gravação explícita antes da fatura nativa');
$hooksCi->db->tables['a_nfse_nacional_invoice_preferences'] = [];
$hooksState['permissions']['invoices'] = ['view'];
hookRefuses(static fn() => nfse_nacional_before_invoice_updated($updatePayload, 101), 'acesso fiscal não substitui permissão nativa de editar fatura');
$hooksState['permissions']['invoices'] = ['view', 'create', 'edit'];
$savedBeforeCompanyChange = $hooksCi->nfse_nacional_model->recipientSaved;
nfse_nacional_before_invoice_updated($updatePayload, 101);
$hooksState['prefix'] = 'b_'; $hooksCi->db->database = 'tenant_b';
hookRefuses(static fn() => nfse_nacional_after_invoice_updated(101), 'troca de empresa entre callbacks bloqueia associação da preferência');
hookCheck($hooksCi->nfse_nacional_model->recipientSaved === $savedBeforeCompanyChange, 'callback de outra empresa não grava preferência pendente');

echo 'OK: ' . $hookChecks . " verificações de integração nativa, acesso, CSRF e ambientes.\n";
