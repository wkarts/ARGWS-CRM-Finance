<?php
defined('BASEPATH') or exit('No direct script access allowed');
require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../helpers/nfse_nacional_helper.php';

use NfseNacional\ApiClient;
use NfseNacional\ApiException;
use NfseNacional\Certificate;
use NfseNacional\Config;
use NfseNacional\Danfe;
use NfseNacional\DpsBuilder;
use NfseNacional\DistributionClient;
use NfseNacional\NationalRepository;
use NfseNacional\NationalService;
use NfseNacional\CertificateResolver;
use NfseNacional\MonthlyPackage;
use NfseNacional\MonthlyDelivery;
use NfseNacional\LifecycleService;
use NfseNacional\EventClient;
use NfseNacional\Substitution;
use NfseNacional\OperationalLog;
use NfseNacional\Service;
use NfseNacional\ValidationException;

class Nfse_nacional extends AdminController
{
    private $fiscal;
    private Service $service;
    private ?int $preparedDocumentId = null;
    private ?OperationalLog $operationLog = null;
    private ?Closure $logFactory = null;
    private ?Closure $nationalFactory = null;
    private ?string $correlationId = null;
    private string $operationName = '';
    private array $operationContext = [];
    private float $operationStarted = 0;
    private bool $operationRecorded = false;

    public function __construct()
    {
        parent::__construct();
        $this->load->model('nfse_nacional/nfse_nacional_model');
        $this->fiscal = $this->nfse_nacional_model;
        $this->logFactory = static fn(): OperationalLog => OperationalLog::current();
        $this->service = new Service($this->fiscal, fn($environment, $certificate): ApiClient =>
            new ApiClient($environment, $certificate, null, fn(array $event) => $this->recordApi($event)));
        $this->output->enable_profiler(false);
        $this->output->set_header('Cache-Control: no-store, private, max-age=0')->set_header('Pragma: no-cache')->set_header('X-Content-Type-Options: nosniff');
    }

    public function index(): void
    {
        if (!$this->pagePermission('report_crm')) { return; }
        $filters = [];
        foreach (['status', 'environment', 'search', 'collection'] as $key) { $value = $this->input->get($key); $filters[$key] = is_scalar($value) ? (string) $value : ''; }
        $rows = $this->fiscal->listDocuments($filters);
        $stats = [];
        foreach ($rows as $r) { $stats[$r['status']] = ($stats[$r['status']] ?? 0) + 1; }
        $ibptExpiredNotice = null;
        try {
            $settings = $this->fiscal->settings();
            if ((string) ($settings['ibpt_show_expired_warning'] ?? '1') === '1') {
                $uf = \NfseNacional\DraftTax::state($settings['provider'] ?? []);
                $table = \NfseNacional\IbptTable::current()->status($uf);
                if (($table['status'] ?? '') === 'expired' && !empty($table['service_count'])) {
                    $ibptExpiredNotice = ['uf' => $uf, 'version' => (string) ($table['version'] ?? ''),
                        'valid_to' => (string) ($table['valid_to'] ?? '')];
                }
            }
        } catch (Throwable $ignored) {
            // The optional, local-only notice must never affect document listing or issuance.
        }
        $this->load->view('nfse_nacional/manage', ['title' => 'NFS-e Nacional', 'documents' => $rows, 'filters' => $filters,
            'stats' => $stats, 'ibpt_expired_notice' => $ibptExpiredNotice]);
    }

    public function reports(): void
    {
        if (!$this->pagePermission('view')) { return; }
        if (!\NfseNacional\Permissions::anyReport()) { access_denied('Relatórios da NFS-e'); return; }
        $this->load->view('nfse_nacional/reports', ['title' => 'Relatórios da NFS-e']);
    }

    public function national($direction = 'issued'): void
    {
        if (!in_array($direction, ['issued', 'received'], true)) { show_404(); return; }
        if (!$this->pagePermission(\NfseNacional\Permissions::reportCapability($direction))) { return; }
        try {
            $settings = $this->fiscal->settings();
            $filters = $this->requestFields(['environment', 'date_from', 'date_to', 'search', 'status'], false);
            $filters['environment'] = NationalRepository::environment($filters['environment'] ?: $settings['environment']);
            $page = $this->input->get('page');
            $page = is_string($page) && preg_match('/^[1-9][0-9]{0,4}$/D', $page) ? (int) $page : 1;
            $run = []; $sync = []; $report = ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'per_page' => 50];
            $ready = false;
            if (\NfseNacional\Validator::taxId($settings['provider']['tax_id'])) {
                $repository = new NationalRepository($this->fiscal);
                $report = $repository->list($direction, $filters, $page, 50);
                $filters = $report['filters'];
                $state = $repository->state($filters['environment']);
                $run = (new \NfseNacional\SyncQueue($this->fiscal))->state($filters['environment']);
                $utc = static fn($date) => $date ? str_replace(' ', 'T', (string) $date) . 'Z' : null;
                $sync = ['last_success_at' => $utc($state['last_synced_at']), 'last_attempt_at' => $utc($state['updated_at']),
                    'next_allowed_at' => $utc($state['next_sync_at']), 'last_error' => $state['last_error'], 'has_more' => (bool) $state['has_more']];
                $ready = (new CertificateResolver($this->fiscal))->inspect()['available'];
            }
        } catch (Throwable $error) { show_error(html_escape($error->getMessage()), $error instanceof InvalidArgumentException ? 422 : 403); return; }
        $this->load->view('nfse_nacional/national', ['title' => 'Documentos nacionais da NFS-e', 'direction' => $direction,
            'filters' => $filters, 'report' => $report, 'sync' => $sync, 'sync_run' => $run, 'configuration_ready' => $ready,
            'can_sync' => nfse_nacional_can('national_sync')]);
    }

    public function national_sync(): void
    {
        $this->mutation('national_sync', function () {
            if (!nfse_nacional_can('national_view')) { throw new RuntimeException('Você não tem permissão para consultar o acervo nacional.', 403); }
            $environment = $this->requestFields(['environment'], true)['environment'];
            $this->operationContext += ['environment' => $environment];
            $mode = Config::text($this->input->post('sync_mode', false) ?? 'full', 12);
            $from = Config::text($this->input->post('sync_date_from', false) ?? '', 10);
            $to = Config::text($this->input->post('sync_date_to', false) ?? '', 10);
            $queue = new \NfseNacional\SyncQueue($this->fiscal, $this->nationalService());
            $queue->start($environment, $mode, $from, $to);
            $queue->run(1, true);
            $run = $queue->state($environment);
            unset($run['issuer_tax_id'], $run['scope'], $run['staff_id']);
            $message = $run['last_error'] ?: ($run['status'] === 'completed'
                ? 'Consulta concluída. O ADN confirmou que não há novos documentos disponíveis nesta execução.'
                : ($run['status'] === 'waiting' ? 'A consulta respeitará a próxima tentativa indicada na execução.' : 'Lote processado a partir do cursor confirmado. A consulta continuará automaticamente.'));
            return ['message' => $message, 'data' => ['reload' => true, 'run' => $run]];
        }, 'national.sync');
    }

    /** Bounded, CSRF-protected processing complementing the native cron; GET polling remains read-only. */
    public function national_sync_process(): void
    {
        $this->mutation('national_sync', function () {
            if (!nfse_nacional_can('national_view')) { throw new RuntimeException('Sem permissão para sincronizar.', 403); }
            $environment = NationalRepository::environment($this->requestFields(['environment'], true)['environment']);
            if ($environment !== $this->fiscal->settings()['environment']) { throw new RuntimeException('Selecione este ambiente nas configurações antes de sincronizar.'); }
            $queue = new \NfseNacional\SyncQueue($this->fiscal, $this->nationalService());
            $queue->run(1, true);
            $run = $queue->state($environment);
            unset($run['issuer_tax_id'], $run['scope'], $run['staff_id']);
            return ['message' => 'Situação da busca atualizada.', 'data' => ['run' => $run]];
        }, 'national.sync');
    }

    public function national_sync_progress(): void
    {
        if (!$this->pagePermission('national_view')) { return; }
        try {
            $environment = NationalRepository::environment(Config::text($this->input->get('environment'), 1));
            $run = (new \NfseNacional\SyncQueue($this->fiscal))->state($environment);
            $canProcess = nfse_nacional_can('national_sync') && (int) ($run['staff_id'] ?? 0) === (int) get_staff_user_id();
            unset($run['issuer_tax_id'], $run['scope'], $run['staff_id']);
            $this->respond(['ok' => true, 'data' => ['run' => $run, 'can_process' => $canProcess]]);
        } catch (Throwable $error) { $this->respond(['ok' => false, 'message' => 'Não foi possível consultar o progresso. Atualize a página.'], 403); }
    }

    public function national_preview($id): void
    {
        $this->nationalFile($id, 'pdf', true);
    }

    public function national_download($id, $kind = 'xml'): void
    {
        $this->nationalFile($id, $kind, false);
    }

    private function nationalFile($id, string $kind, bool $inline): void
    {
        if (!$this->pagePermission('national_view')) { return; }
        if (!in_array($kind, ['xml', 'pdf'], true)) { show_404(); return; }
        $this->beginOperation($inline ? 'national.preview' : 'national.download_' . $kind, ['national_document_id' => $id]);
        try {
            $id = $this->id($id); $repository = new NationalRepository($this->fiscal);
            $record = $repository->document($id);
            if ($record['direction'] === 'both') {
                if (!nfse_nacional_can('report_issued') && !nfse_nacional_can('report_received')) { throw new RuntimeException('Sem permissão para este relatório.', 403); }
            } else { \NfseNacional\Permissions::assertReport($record['direction'] === 'received' ? 'received' : 'issued'); }
            if (!nfse_nacional_can('report_export')) { throw new RuntimeException('Você não tem permissão para baixar este relatório.', 403); }
            $this->operationContext += ['environment' => $record['environment']];
            $bytes = $kind === 'xml' ? $repository->xml($id) : $this->nationalService()->pdf($id);
            $name = ($kind === 'pdf' ? 'DANFSe-' : 'NFSe-') . $record['access_key'] . '.' . $kind;
        } catch (Throwable $error) {
            $this->recordFailure($error, 422);
            show_error(html_escape($error->getMessage()), in_array((int) $error->getCode(), [403, 404], true) ? (int) $error->getCode() : 422); return;
        }
        $this->finishOperation('info', 'Documento nacional disponibilizado.', ['operation_status' => 'success', 'response_bytes' => strlen($bytes)]);
        $this->output->set_content_type($kind === 'pdf' ? 'application/pdf' : 'application/xml')
            ->set_header('Cache-Control: no-store, private, max-age=0')->set_header('Pragma: no-cache')
            ->set_header('X-Content-Type-Options: nosniff')->set_header('X-Frame-Options: SAMEORIGIN')
            ->set_header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"')->set_output($bytes);
    }

    private function nationalService(): NationalService
    {
        if ($this->nationalFactory !== null) { return ($this->nationalFactory)($this->fiscal); }
        return new NationalService($this->fiscal, fn($environment, $certificate): DistributionClient =>
            new DistributionClient($environment, $certificate, null, fn(array $event) => $this->recordApi($event)));
    }

    private function requestFields(array $keys, bool $post): array
    {
        $fields = [];
        foreach ($keys as $key) {
            $value = $post ? $this->input->post($key, false) : $this->input->get($key);
            if ($value !== null && !is_string($value)) { throw new InvalidArgumentException('Parâmetro de consulta inválido.'); }
            $fields[$key] = $value ?? '';
        }
        return $fields;
    }

    public function accounting(): void
    {
        if (!$this->pagePermission('report_package')) { return; }
        $settings = $this->fiscal->settings(); $report = null; $history = []; $pageError = '';
        try {
            $filters = $this->requestFields(['environment', 'month', 'direction', 'format'], false);
            $confer = $filters['month'] !== '';
            $filters = MonthlyPackage::filters(['environment' => $filters['environment'] ?: $settings['environment'],
                'month' => $filters['month'] ?: (new DateTimeImmutable('first day of previous month', new DateTimeZone('America/Bahia')))->format('Y-m'),
                'direction' => $filters['direction'] ?: 'both', 'format' => $filters['format'] ?: 'both']);
            if (\NfseNacional\Validator::taxId($settings['provider']['tax_id'])) {
                if ($confer) { $report = (new MonthlyPackage($this->fiscal))->report($filters); }
                $history = array_values(array_filter((new MonthlyDelivery($this->fiscal))->history(), static fn($row) =>
                    $row['environment'] === $filters['environment'] && (!$confer || $row['month'] === $filters['month'])));
            } else { $pageError = 'Configure o CPF/CNPJ da empresa para conferir os documentos e preparar o pacote mensal.'; }
        } catch (Throwable $error) { $pageError = $error->getMessage(); }
        $this->load->view('nfse_nacional/accounting', ['title' => 'Pacote mensal de NFS-e', 'filters' => $filters ?? [],
            'report' => $report, 'history' => $history, 'recipients' => $settings['accounting_recipients'] ?? [],
            'can_send' => nfse_nacional_can('accounting_send'), 'page_error' => $pageError]);
    }

    public function accounting_download(): void
    {
        if (!$this->postPermission('report_package')) { return; }
        if (!$this->postPermission('report_export')) { return; }
        $this->beginOperation('accounting.download');
        try {
            $fields = $this->requestFields(['environment', 'month', 'direction', 'format', 'fingerprint'], true);
            $package = (new MonthlyPackage($this->fiscal))->build($fields, $fields['fingerprint']);
            $this->finishOperation('info', 'Pacote mensal gerado e disponibilizado para download.', ['environment' => $fields['environment'],
                'operation_status' => 'success', 'document_count' => $package['count'], 'response_bytes' => strlen($package['bytes']), 'response_sha256' => $package['sha256']]);
            $this->output->set_content_type('application/zip')
                ->set_header('Content-Disposition: attachment; filename="' . $package['filename'] . '"')
                ->set_header('X-NFSe-CSRF-Name: ' . $this->security->get_csrf_token_name())
                ->set_header('X-NFSe-CSRF-Hash: ' . $this->security->get_csrf_hash())->set_output($package['bytes']);
        } catch (Throwable $error) { $this->recordFailure($error, 422); $this->respond(['ok' => false, 'message' => $error->getMessage()], 422); }
    }

    public function accounting_send(): void
    {
        $this->mutation('accounting_send', function () {
            if (!nfse_nacional_can('report_package')) { throw new RuntimeException('Você não tem permissão para acessar o pacote mensal.'); }
            $fields = $this->requestFields(['environment', 'month', 'direction', 'format', 'fingerprint'], true);
            $recipientIds = $this->input->post('recipient_ids', false);
            if (!is_array($recipientIds)) { throw new InvalidArgumentException('Selecione os destinatários cadastrados para receber os documentos.'); }
            $result = (new MonthlyDelivery($this->fiscal))->send($fields, $fields['fingerprint'], $recipientIds);
            $this->operationContext += ['environment' => $fields['environment'], 'delivery_id' => $result['id'], 'recipient_count' => count($recipientIds)];
            return ['message' => $result['message'], 'data' => ['reload' => true, 'delivery_id' => $result['id'], 'status' => $result['status']]];
        }, 'accounting.send');
    }

    private function postPermission(string $capability): bool
    {
        if (strtoupper($this->input->method()) !== 'POST') { $this->respond(['ok' => false, 'message' => 'Use POST para esta operação.'], 405); return false; }
        if (!nfse_nacional_can($capability)) { $this->respond(['ok' => false, 'message' => 'Você não tem permissão para esta operação.'], 403); return false; }
        if (!(bool) $this->config->item('csrf_protection')) { $this->respond(['ok' => false, 'message' => 'Ative a proteção CSRF nativa do Perfex.'], 503); return false; }
        return true;
    }

    public function logs(): void
    {
        if (!$this->pagePermission('logs')) { return; }
        try { $filters = $this->logFilters(); }
        catch (InvalidArgumentException $error) { show_error(html_escape($error->getMessage()), 400); return; }
        $value = $this->input->get('page');
        $page = is_string($value) && preg_match('/^[1-9][0-9]{0,4}$/D', $value) ? min(10000, (int) $value) : 1;
        $store = $this->logStore();
        $report = $store ? $store->report($filters, $page, 50) : ['rows' => [], 'total' => 0, 'page' => 1,
            'pages' => 1, 'per_page' => 50, 'has_more' => false, 'truncated' => false,
            'storage' => 'unavailable', 'storage_available' => false, 'retention_days' => 30];
        $this->load->view('nfse_nacional/logs', ['title' => 'Logs da NFS-e', 'report' => $report,
            'filters' => $filters, 'operations' => $this->logOperations(), 'export_limit' => 2000]);
    }

    public function log_detail(): void
    {
        if (!$this->pagePermission('logs')) { return; }
        try { $filters = $this->logFilters(); }
        catch (InvalidArgumentException $error) { show_error(html_escape($error->getMessage()), 400); return; }
        $id = $this->input->get('event_id');
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) { show_404(); return; }
        $store = $this->logStore();
        if (!$store) { show_error('O armazenamento dos registros está indisponível.', 503); return; }
        $record = $store->find($id);
        if (!$record) { show_404(); return; }
        $page = $this->input->get('page');
        if (is_string($page) && preg_match('/^[1-9][0-9]{0,4}$/D', $page)) { $filters['page'] = $page; }
        $this->load->view('nfse_nacional/log_detail', ['title' => 'Detalhes do log da NFS-e',
            'record' => $record, 'filters' => $filters, 'operations' => $this->logOperations()]);
    }

    public function logs_download(): void
    {
        if (!$this->pagePermission('logs')) { return; }
        try { $filters = $this->logFilters(); }
        catch (InvalidArgumentException $error) { show_error(html_escape($error->getMessage()), 400); return; }
        $store = $this->logStore();
        if (!$store) { show_error('O armazenamento dos registros está indisponível.', 503); return; }
        $export = $store->export($filters);
        if (($export['storage'] ?? '') === 'unavailable') { show_error('Não foi possível ler os registros.', 503); return; }
        $this->beginOperation('logs.export');
        $this->finishOperation('info', 'Histórico de diagnóstico exportado.', [
            'operation_status' => $export['truncated'] ? 'partial' : 'success',
            'response_bytes' => strlen($export['content']), 'details_omitted' => (bool) $export['truncated'],
        ]);
        $this->output->set_content_type('application/x-ndjson', 'utf-8')
            ->set_header('Cache-Control: no-store, private, max-age=0')
            ->set_header('X-Content-Type-Options: nosniff')
            ->set_header('X-NFSe-Log-Records: ' . (int) $export['count'])
            ->set_header('X-NFSe-Log-Truncated: ' . ($export['truncated'] ? '1' : '0'))
            ->set_header('Content-Disposition: attachment; filename="nfse-logs-' . gmdate('Ymd-His') . '.jsonl"')
            ->set_output($export['content']);
    }

    /** Enriched report with all entries from a selected operation or current filters. */
    public function logs_diagnostic(): void
    {
        if (!$this->pagePermission('logs')) { return; }
        try { $filters = $this->logFilters(); }
        catch (InvalidArgumentException $error) { show_error(html_escape($error->getMessage()), 400); return; }
        $eventId = $this->input->get('event_id');
        if ($eventId !== null && (!is_string($eventId) || !preg_match('/^[a-f0-9]{32}$/D', $eventId))) {
            show_404(); return;
        }
        $store = $this->logStore();
        if (!$store) { show_error('O armazenamento dos registros está indisponível.', 503); return; }
        $result = $store->detailedExport($filters, $eventId);
        if ($result === null) { show_404(); return; }
        if ($result['storage'] === 'unavailable') { show_error('Não foi possível ler os registros.', 503); return; }
        $this->beginOperation('logs.export');
        $this->finishOperation('info', 'Diagnóstico detalhado exportado.', [
            'operation_status' => $result['truncated'] ? 'partial' : 'success',
            'response_bytes' => strlen($result['content']), 'details_omitted' => (bool) $result['truncated'],
        ]);
        $suffix = $eventId === null ? gmdate('Ymd-His') : $eventId;
        $this->output->set_content_type('application/json', 'utf-8')
            ->set_header('Cache-Control: no-store, private, max-age=0')
            ->set_header('Pragma: no-cache')
            ->set_header('X-Content-Type-Options: nosniff')
            ->set_header('X-Frame-Options: DENY')
            ->set_header('X-NFSe-Log-Records: ' . (int) $result['count'])
            ->set_header('X-NFSe-Log-Truncated: ' . ($result['truncated'] ? '1' : '0'))
            ->set_header('Content-Disposition: attachment; filename="nfse-diagnostico-' . $suffix . '.json"')
            ->set_output($result['content']);
    }

    /** Fixed public assets, served by the active company's authenticated runtime. */
    public function asset($key = ''): void
    {
        $method = strtoupper($this->input->method());
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            $this->output->set_status_header(405)->set_header('Allow: GET, HEAD')->set_output('');
            return;
        }
        if (!nfse_nacional_can('view') && !nfse_nacional_settings_allowed()) {
            $this->output->set_status_header(403)->set_output('');
            return;
        }
        try { $asset = \NfseNacional\Assets::read(is_string($key) ? $key : ''); }
        catch (InvalidArgumentException $error) { $this->output->set_status_header(404)->set_output(''); return; }
        catch (Throwable $error) { $this->output->set_status_header(503)->set_output(''); return; }
        $requested = $this->input->get('v');
        if ($requested !== null && (!is_string($requested) || !hash_equals($asset['hash'], $requested))) {
            $this->output->set_status_header(409)->set_output('');
            return;
        }
        $etag = '"' . $asset['hash'] . '"';
        $this->output->set_content_type($asset['mime'], 'utf-8')
            ->set_header('Cache-Control: private, max-age=3600, must-revalidate')
            ->set_header('Pragma: private')->set_header('Vary: Cookie')
            ->set_header('ETag: ' . $etag)->set_header('X-Content-Type-Options: nosniff');
        if ($this->input->get_request_header('If-None-Match') === $etag) {
            $this->output->set_status_header(304)->set_output('');
            return;
        }
        $this->output->set_output($method === 'HEAD' ? '' : $asset['content']);
    }

    /** Reads public registration data only. Saving remains the normal form action. */
    public function lookup($type = ''): void
    {
        $capability = nfse_nacional_can('edit') ? 'edit' : 'settings';
        $this->mutation($capability, function () use ($type) {
            $value = $this->input->post('value', false);
            if (!is_string($type) || !is_string($value)) {
                throw new InvalidArgumentException('Informe o CNPJ ou CEP para consultar.');
            }
            $value = \NfseNacional\RegistrationLookup::identifier($type, $value);
            try { $result = \NfseNacional\RegistrationLookup::current()->lookup($type, $value); }
            catch (InvalidArgumentException $error) { throw $error; }
            catch (Throwable $error) {
                if ((int) $error->getCode() === 429) {
                    $this->output->set_header('Retry-After: 60');
                    throw new RuntimeException('Aguarde um minuto antes de realizar novas consultas.', 429, $error);
                }
                log_message('error', 'NFS-e Nacional: consulta cadastral indisponível (' . get_class($error) . ').');
                throw new RuntimeException('Não foi possível consultar os dados agora. Aguarde um momento ou preencha os campos.');
            }
            $this->operationContext += ['field_count' => count($result['fields'] ?? []), 'field_names' => array_keys($result['fields'] ?? [])];
            return ['message' => $result['message'], 'data' => $result];
        }, in_array($type, ['cnpj', 'cep'], true) ? 'registration.' . $type : 'registration.lookup', []);
    }

    /** Refreshes only the saved issuer's public table; no fiscal document is changed. */
    public function ibpt_refresh(): void
    {
        $this->mutation('settings', function () {
            $settings = $this->fiscal->settings();
            $uf = \NfseNacional\DraftTax::state($settings['provider']);
            $expected = $this->input->post('expected_uf', false);
            if (!is_string($expected) || !hash_equals($uf, $expected)) {
                throw new RuntimeException('A UF do emitente mudou. Reabra as configurações antes de atualizar a tabela IBPT.');
            }
            $allowOlder = (string) ($settings['ibpt_allow_older_table'] ?? '0') === '1';
            $table = \NfseNacional\IbptTable::current()->refresh($uf, $allowOlder);
            $this->operationContext += ['uf' => $uf, 'older_version_selected' => (bool) ($table['auto_refresh_paused'] ?? false),
                'ibpt_key_origin' => $table['key_origin'] ?? '', 'ibpt_key' => $table['key'] ?? '',
                'ibpt_version' => $table['version'] ?? '', 'ibpt_valid_from' => $table['valid_from'] ?? '',
                'ibpt_valid_to' => $table['valid_to'] ?? ''];
            return ['message' => $table['refresh_message'] ?: $table['message'], 'data' => ['ibpt_table' => $table]];
        }, 'ibpt.refresh', []);
    }

    /** Imports a local CSV into the active issuer's protected table cache only. */
    public function ibpt_import(): void
    {
        $this->mutation('settings', function () {
            $settings = $this->fiscal->settings();
            $uf = \NfseNacional\DraftTax::state($settings['provider']);
            $expected = $this->input->post('expected_uf', false);
            if (!is_string($expected) || !hash_equals($uf, $expected)) {
                throw new RuntimeException('A UF do emitente mudou. Reabra as configurações antes de enviar a tabela IBPT.');
            }
            $this->operationContext += ['uf' => $uf];
            $file = $_FILES['ibpt_file'] ?? null;
            if (!is_array($file) || !is_int($file['error'] ?? null) || $file['error'] === UPLOAD_ERR_NO_FILE) {
                throw new InvalidArgumentException('Selecione um arquivo CSV da tabela IBPT da UF do emitente.');
            }
            $limit = 8 * 1024 * 1024;
            if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                throw new InvalidArgumentException('O arquivo excede o limite de envio. Use um CSV de até 8 MB, respeitando também o limite de upload do servidor.');
            }
            if ($file['error'] !== UPLOAD_ERR_OK || !is_string($file['name'] ?? null)
                || !is_string($file['tmp_name'] ?? null) || !is_int($file['size'] ?? null)
                || $file['size'] < 1 || $file['size'] > $limit || !is_uploaded_file($file['tmp_name'])) {
                throw new InvalidArgumentException('Envie um único arquivo CSV válido da tabela IBPT, de até 8 MB.');
            }
            if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
                throw new InvalidArgumentException('O arquivo da tabela IBPT deve ter extensão .csv.');
            }
            $bytes = @file_get_contents($file['tmp_name'], false, null, 0, $limit + 1);
            if (!is_string($bytes) || strlen($bytes) !== $file['size'] || strlen($bytes) > $limit) {
                throw new InvalidArgumentException('Não foi possível ler o arquivo completo. Selecione o CSV novamente.');
            }
            $key = $this->input->post('ibpt_key', false) ?? '';
            if (!is_string($key) || strlen($key) > 64) {
                throw new InvalidArgumentException('Informe uma chave IBPT original válida, com até 32 letras e números.');
            }
            $key = trim($key);
            if ($key !== '' && !preg_match('/\A[A-Za-z0-9]{1,32}\z/D', $key)) {
                throw new InvalidArgumentException('Informe uma chave IBPT original válida, com até 32 letras e números.');
            }
            $this->operationContext += ['request_bytes' => strlen($bytes)];
            $allowOlder = (string) ($settings['ibpt_allow_older_table'] ?? '0') === '1';
            $table = \NfseNacional\IbptTable::current()->import($uf, $bytes, $file['name'], $key, $allowOlder);
            $this->operationContext += ['key_supplied_manually' => ($table['key_origin'] ?? '') === 'manual',
                'older_version_selected' => (bool) ($table['auto_refresh_paused'] ?? false)];
            $this->operationContext += ['ibpt_key' => $table['key'] ?? '', 'ibpt_version' => $table['version'] ?? '',
                'ibpt_valid_from' => $table['valid_from'] ?? '', 'ibpt_valid_to' => $table['valid_to'] ?? ''];
            return ['message' => $table['refresh_message'] ?: 'Tabela IBPT importada e disponível localmente.',
                'data' => ['ibpt_table' => $table]];
        }, 'ibpt.import', []);
    }

    public function settings(): void
    {
        if (strtoupper($this->input->method()) === 'POST') {
            $this->mutation('settings', function () {
                $input = $this->input->post('settings', false);
                if (!is_array($input)) { throw new InvalidArgumentException('Configurações inválidas.'); }
                $priorSettings = $this->fiscal->settings();
                $settings = Config::sanitize($input, $priorSettings);
                foreach (['paid', 'created'] as $event) {
                    if ($settings['automations'][$event] !== $priorSettings['automations'][$event]) {
                        if (!is_admin()) { throw new RuntimeException('Somente o administrador pode alterar a emissão automática.'); }
                        $settings['automations'][$event]['staff_id'] = (string) get_staff_user_id();
                    }
                }
                $credentials = null; $metadata = null;
                $file = $_FILES['certificate'] ?? null;
                if ($file && (int) $file['error'] !== UPLOAD_ERR_NO_FILE) {
                    if ((int) $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) || (int) $file['size'] < 1 || (int) $file['size'] > 4 * 1024 * 1024) {
                        throw new InvalidArgumentException('Envie um certificado .pfx ou .p12 válido, de até 4 MB.');
                    }
                    if (!in_array(strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)), ['pfx', 'p12'], true)) { throw new InvalidArgumentException('O certificado deve ter extensão .pfx ou .p12.'); }
                    $password = $this->input->post('certificate_password', false);
                    if (!is_string($password) || strlen($password) > 1024) { throw new InvalidArgumentException('Senha do certificado inválida.'); }
                    $bytes = file_get_contents($file['tmp_name']);
                    if ($bytes === false || strlen($bytes) > 4 * 1024 * 1024) { throw new InvalidArgumentException('Falha ao ler o certificado recebido.'); }
                    if ($settings['provider']['tax_id'] === '') { throw new InvalidArgumentException('Informe o CPF/CNPJ do emitente antes de importar o certificado.'); }
                    $certificate = Certificate::fromPkcs12($bytes, $password, $settings['provider']['tax_id']);
                    $metadata = $certificate->metadata();
                    $credentials = Nfse_nacional_model::json(['pfx' => base64_encode($bytes), 'password' => $password]);
                }
                // A priority local certificate must remain consistent with the edited issuer.
                $previous = $this->fiscal->settingsRow();
                if ($credentials === null && $settings['use_local_certificate'] === '1' && !empty($previous['certificate_enc']) && $settings['provider']['tax_id'] !== '') {
                    $stored = Nfse_nacional_model::decode($this->fiscal->crypto()->decrypt($previous['certificate_enc']));
                    $pfx = base64_decode((string) ($stored['pfx'] ?? ''), true);
                    if ($pfx === false) { throw new RuntimeException('O certificado local está inválido. Importe o arquivo novamente.'); }
                    Certificate::fromPkcs12($pfx, (string) ($stored['password'] ?? ''), $settings['provider']['tax_id']);
                }
                $companyTaxId = function_exists('get_option') ? strtoupper(preg_replace('/[.\/\-\s]+/', '', (string) get_option('company_vat'))) : '';
                if (\NfseNacional\Validator::taxId($companyTaxId) && $settings['provider']['tax_id'] !== '' && !hash_equals($companyTaxId, $settings['provider']['tax_id'])) {
                    throw new InvalidArgumentException('O CPF/CNPJ do emitente deve corresponder ao cadastro da empresa ativa.');
                }
                // Shared credentials only; keep all legacy records protected and unchanged.
                $material = $this->fiscal->authMaterial();
                $choice = $this->input->post('auth_saved_certificate', false);
                if ($choice !== null && $choice !== '') {
                    $local = empty($previous['certificate_enc']) ? null : Nfse_nacional_model::decode($this->fiscal->crypto()->decrypt($previous['certificate_enc']));
                    $candidates = \NfseNacional\UnifiedAuth::certificates($material, $local);
                    if (!is_string($choice) || !isset($candidates[$choice]) || $credentials !== null) { throw new InvalidArgumentException('Escolha um certificado anterior ou envie um novo arquivo.'); }
                    $record = $candidates[$choice];
                    $bytes = base64_decode((string) $record['pfx'], true);
                    if ($bytes === false) { throw new RuntimeException('Certificado anterior inválido.'); }
                    $metadata = Certificate::fromPkcs12($bytes, (string) $record['password'], $settings['provider']['tax_id'])->metadata();
                    $credentials = Nfse_nacional_model::json($record);
                }
                if ($credentials !== null) {
                    if (!empty($previous['certificate_enc'])) { $material['legacy_local_certificate'] ??= Nfse_nacional_model::decode($this->fiscal->crypto()->decrypt($previous['certificate_enc'])); }
                    $settings['auth_certificate_conflict'] = '0';
                    $settings['use_local_certificate'] = '1';
                }
                $authInputs = [];
                foreach (['auth_username', 'auth_password', 'auth_clear_login'] as $field) {
                    $value = $this->input->post($field, false);
                    if ($value !== null && !is_string($value)) { throw new InvalidArgumentException('Credenciais inválidas.'); }
                    $authInputs[$field] = $value ?? ($field === 'auth_clear_login' ? '0' : '');
                }
                $loginChoice = $this->input->post('auth_saved_login', false);
                if ($loginChoice !== null && $loginChoice !== '') {
                    if (!is_string($loginChoice) || !in_array($loginChoice, ['1', '2'], true) || empty($material[$loginChoice]['login'])) { throw new InvalidArgumentException('Credencial anterior indisponível.'); }
                    if ($authInputs['auth_username'] !== '' || $authInputs['auth_password'] !== '' || $authInputs['auth_clear_login'] === '1') { throw new InvalidArgumentException('Escolha as credenciais anteriores ou preencha novas credenciais.'); }
                    $material['shared_login'] = $material[$loginChoice]['login'];
                }
                $authMaterial = \NfseNacional\EnvironmentAuth::prepareMaterial($material, $authInputs, [], $settings['provider']['tax_id']);
                $authChanged = $authMaterial !== $this->fiscal->authMaterial();
                $this->fiscal->saveSettings($settings, $credentials, $metadata, $authMaterial);
                $this->operationContext += ['environment' => $settings['environment'], 'certificate_changed' => $credentials !== null,
                    'auth_material_changed' => $authChanged, 'local_priority' => $settings['use_local_certificate'] === '1'];
                return ['message' => 'Configurações salvas para a empresa ativa.', 'data' => ['reload' => true]];
            }, 'settings.save', []);
            return;
        }
        if (!$this->pagePermission('settings')) { return; }
        redirect(nfse_nacional_settings_url());
    }

    public function competence_rates(): void
    {
        if (!$this->pagePermission('rates_view')) { return; }
        if (!\NfseNacional\CompetenceRate::enabled($this->fiscal->settings())) { show_404(); return; }
        $type = $this->input->get('rate_type') ?? 'simple';
        if (!is_string($type) || !in_array($type, ['simple', 'iss'], true)) { show_404(); return; }
        $search = Config::text($this->input->get('search') ?? '', 40);
        $page = max(1, min(100000, (int) $this->input->get('page')));
        $db = $this->fiscal->db; $issuer = $this->fiscal->settings()['provider']['tax_id'];
        $rows = $db->where('issuer', $issuer)->order_by('competence', 'desc')->get($this->fiscal->table(\NfseNacional\CompetenceRate::tableSuffix($type)))->result_array();
        if ($search !== '') { $term = str_replace(',', '.', $search); $rows = array_values(array_filter($rows, static fn($row) => str_contains(substr($row['competence'], 5, 2) . '/' . substr($row['competence'], 0, 4) . ' ' . $row['competence'] . ' ' . $row['rate'], $term))); }
        $total = count($rows); $pages = max(1, (int) ceil($total / 25)); $page = min($page, $pages);
        $this->load->view('nfse_nacional/competence_rates', ['title' => 'Alíquotas por competência', 'rate_type' => $type, 'rate_settings' => $this->fiscal->settings(), 'rates' => array_slice($rows, ($page - 1) * 25, 25), 'search' => $search, 'page' => $page, 'pages' => $pages, 'total' => $total]);
    }
    public function competence_rate_lookup(): void
    {
        if (strtoupper($this->input->method()) !== 'GET') { $this->respond(['ok' => false, 'message' => 'Use GET para consultar.'], 405); return; }
        if (!nfse_nacional_can('rates_view') && !nfse_nacional_can('settings')) { $this->respond(['ok' => false, 'message' => 'Sem permissão para consultar alíquotas.'], 403); return; }
        try {
            $month = \NfseNacional\CompetenceRate::month(Config::text($this->input->get('competence'), 10));
            $row = $this->fiscal->competenceRate($month);
            $this->respond(['ok' => true, 'data' => ['competence' => $month, 'rate' => $row ? (string) $row['rate'] : null, 'iss_rate' => ($iss = $this->fiscal->competenceRate($month, 'iss')) ? (string) $iss['rate'] : null]]);
        } catch (Throwable $error) { $this->respond(['ok' => false, 'message' => 'Não foi possível consultar a competência informada.'], 422); }
    }

    public function competence_rate_save(): void
    {
        $this->mutation('rates_view', function () {
            if (!\NfseNacional\CompetenceRate::enabled($this->fiscal->settings())) { throw new RuntimeException('Habilite a alíquota variável nas configurações.'); }
            $month = \NfseNacional\CompetenceRate::month(Config::text($this->input->post('competence', false), 10));
            $type = \NfseNacional\CompetenceRate::type(Config::text($this->input->post('rate_type', false) ?? 'simple', 10));
            $rate = $type === 'iss' ? \NfseNacional\CompetenceRate::issPercentage($this->input->post('rate', false)) : \NfseNacional\CompetenceRate::percentage($this->input->post('rate', false));
            $issuer = $this->fiscal->settings()['provider']['tax_id'];
            $original = Config::text($this->input->post('original_competence', false) ?? '', 10);
            if (!nfse_nacional_can($original === '' ? 'rates_create' : 'rates_edit')) { throw new RuntimeException('Você não tem permissão para ' . ($original === '' ? 'incluir' : 'editar') . ' alíquotas.', 403); }
            $this->fiscal->lock('competence-rates', function () use ($original, $issuer, $month, $rate, $type) {
                $db = $this->fiscal->db; $table = $this->fiscal->table(\NfseNacional\CompetenceRate::tableSuffix($type));
                $existing = $db->where('issuer', $issuer)->where('competence', $month)->get($table)->row_array();
                $row = ['issuer' => $issuer, 'competence' => $month, 'rate' => $rate, 'updated_by' => get_staff_user_id(), 'updated_at' => date('Y-m-d H:i:s')];
                if ($original === '') {
                    if ($existing) { throw new InvalidArgumentException('Esta competência já está cadastrada. Use Editar para alterar a alíquota.'); }
                    $ok = $db->insert($table, $row);
                } else {
                    $oldMonth = \NfseNacional\CompetenceRate::month(Config::text($original, 10));
                    if ($month !== $oldMonth) { throw new InvalidArgumentException('A competência não pode ser alterada na edição. Inclua outro registro.'); }
                    if (!$existing) { throw new RuntimeException('Registro removido. Atualize a grid.'); }
                    $ok = $db->where('issuer', $issuer)->where('competence', $month)->update($table, $row);
                }
                if ($ok === false) { throw new RuntimeException('Não foi possível salvar a alíquota.'); }
            });
            return ['message' => 'Alíquota salva para a competência.', 'data' => ['reload' => true]];
        }, 'competence.rate.save');
    }

    public function competence_rate_delete(): void
    {
        $this->mutation('rates_delete', function () {
            if (!\NfseNacional\CompetenceRate::enabled($this->fiscal->settings())) { throw new RuntimeException('Habilite a alíquota variável nas configurações.'); }
            $month = \NfseNacional\CompetenceRate::month(Config::text($this->input->post('competence', false), 10));
            $type = \NfseNacional\CompetenceRate::type(Config::text($this->input->post('rate_type', false) ?? 'simple', 10));
            if (!nfse_nacional_can('rates_view')) { throw new RuntimeException('Sem permissão para visualizar alíquotas.', 403); }
            $this->fiscal->db->where('issuer', $this->fiscal->settings()['provider']['tax_id'])->where('competence', $month)
                ->delete($this->fiscal->table(\NfseNacional\CompetenceRate::tableSuffix($type)));
            return ['message' => 'Alíquota removida. Novas emissões da competência exigirão seu cadastro.', 'data' => ['reload' => true]];
        }, 'competence.rate.delete');
    }

    public function automation(): void
    {
        if (!$this->pagePermission('automation_view')) { return; }
        $settings = $this->fiscal->settings();
        if (!\NfseNacional\AutomationPolicy::enabled($settings)) { show_404(); return; }
        $type = $this->input->get('type') ?? 'paid';
        if (!is_string($type) || !in_array($type, ['paid', 'created'], true)) { show_404(); return; }
        $clientId = filter_var($this->input->get('client_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        $page = max(1, min(100000, (int) $this->input->get('page')));
        $recipients = [];
        if ($clientId) { $this->fiscal->client($clientId); $recipients = $this->fiscal->recipients($clientId); }
        $db = $this->fiscal->db;
        $base = function () use ($db, $settings, $type) { return $db->where('scope', nfse_nacional_scope())->where('issuer', $settings['provider']['tax_id'])->where('trigger_event', $type); };
        $total = $base()->count_all_results($this->fiscal->table('automation_jobs'));
        $jobs = $base()->order_by('id', 'desc')->limit(50, ($page - 1) * 50)->get($this->fiscal->table('automation_jobs'))->result_array();
        $targets = $db->where('trigger_event', $type)->order_by('client_id', 'asc')->get($this->fiscal->table('automation_targets'))->result_array();
        $clients = $db->select('userid,company')->order_by('company', 'asc')->get(db_prefix() . 'clients')->result_array();
        $clients = array_values(array_filter($clients, static fn($c) => nfse_nacional_client_allowed($c['userid'])));
        $this->load->view('nfse_nacional/automation', ['title' => 'Automações', 'jobs' => $jobs, 'targets' => $targets,
            'clients' => $clients, 'recipients' => $recipients, 'selected_client' => $clientId, 'automation' => $settings['automations'][$type],
            'type' => $type, 'page' => $page, 'pages' => max(1, (int) ceil($total / 50))]);
    }
    public function automation_target(): void
    {
        $this->mutation('automation_manage', function () {
            if (!nfse_nacional_can('automation_view')) { throw new RuntimeException('Sem permissão para acompanhar automações.', 403); }
            $type = Config::text($this->input->post('type', false), 12);
            \NfseNacional\AutomationPolicy::rule($this->fiscal->settings(), $type);
            $clientId = $this->id($this->input->post('client_id'));
            $this->fiscal->client($clientId);
            $selection = Config::text($this->input->post('selection', false), 64);
            if ($this->input->post('remove') !== '1' && $selection !== '*') { $selection = $this->fiscal->validateInvoiceRecipientSelection($clientId, $selection); }
            $db = $this->fiscal->db;
            if ($this->input->post('remove') === '1') {
                $ok = $db->where('client_id', $clientId)->where('recipient_selection', $selection)->where('trigger_event', $type)->delete($this->fiscal->table('automation_targets'));
            } else {
                $ok = $db->query('INSERT IGNORE INTO `' . $this->fiscal->table('automation_targets') . '` (client_id,recipient_selection,trigger_event) VALUES (?,?,?)', [$clientId, $selection, $type]);
            }
            if ($ok === false) { throw new RuntimeException('Não foi possível salvar a seleção.'); }
            return ['message' => 'Seleção da automação atualizada.', 'data' => ['reload' => true]];
        }, 'automation.target');
    }
    public function automation_action(): void
    {
        $this->mutation('automation_manage', function () {
            if (!nfse_nacional_can('transmit')) { throw new RuntimeException('Sem permissão para gerenciar a emissão.'); }
            $id = $this->id($this->input->post('id'));
            $action = Config::text($this->input->post('action', false), 12);
            if (!in_array($action, ['cancel', 'resume'], true)) { throw new InvalidArgumentException('Ação inválida.'); }
            $row = $this->fiscal->db->where('id', $id)->where('scope', nfse_nacional_scope())
                ->where('issuer', $this->fiscal->settings()['provider']['tax_id'])->get($this->fiscal->table('automation_jobs'))->row_array();
            if (!$row) { throw new RuntimeException('Agendamento indisponível.'); }
            $this->fiscal->client((int) $row['client_id']);
            return $this->fiscal->lock('invoice:' . $row['invoice_id'], function () use ($id, $action) {
                $row = $this->fiscal->db->where('id', $id)->get($this->fiscal->table('automation_jobs'))->row_array();
                if (!in_array($row['status'], ['pending', 'blocked', 'review', 'processing', 'checking'], true)) { throw new RuntimeException('Este agendamento já foi encerrado.'); }
                $status = $action === 'cancel' ? 'cancelled' : 'pending';
                if ($action === 'resume' && $row['document_id']) {
                    $doc = $this->fiscal->document((int) $row['document_id']);
                    if ((int) $doc['attempts'] > 0) { $status = 'checking'; }
                }
                $ok = $this->fiscal->db->where('id', $id)->update($this->fiscal->table('automation_jobs'), ['status' => $status,
                    'due_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
                    'last_error' => $action === 'cancel' ? 'Agendamento cancelado pelo usuário.' : 'Retomada solicitada; será revalidada pelo cron. Transmissões anteriores serão consultadas antes de qualquer reenvio.']);
                if ($ok === false) { throw new RuntimeException('Não foi possível atualizar o agendamento.'); }
                return ['message' => 'Agendamento atualizado.', 'data' => ['reload' => true]];
            });
        }, 'automation.action');
    }

    public function email_recipient_save(): void
    {
        $this->mutation('settings', function () {
            return $this->fiscal->lock('email-recipients', function () {
                $settings = $this->fiscal->settings();
                $rows = $settings['accounting_recipients'];
                $id = Config::text($this->input->post('id', false) ?? '', 16);
                $row = ['id' => $id, 'name' => $this->input->post('name', false), 'email' => $this->input->post('email', false)];
                if (trim((string) $row['name']) === '' || trim((string) $row['email']) === '') { throw new InvalidArgumentException('Informe o nome e o e-mail.'); }
                $found = false;
                foreach ($rows as &$item) { if ($id !== '' && $item['id'] === $id) { $item = $row; $found = true; break; } } unset($item);
                if ($id !== '' && !$found) { throw new RuntimeException('O destinatário foi removido. Reabra a guia.'); }
                if (!$found) { $rows[] = $row; }
                $settings = Config::sanitize(['accounting_recipients' => $rows], $settings);
                $this->fiscal->saveSettings($settings);
                return ['message' => 'Destinatário salvo.', 'data' => ['recipients' => $settings['accounting_recipients']]];
            });
        }, 'email.recipient.save');
    }

    public function email_recipient_delete(): void
    {
        $this->mutation('settings', function () {
            return $this->fiscal->lock('email-recipients', function () {
                $settings = $this->fiscal->settings();
                $id = Config::text($this->input->post('id', false), 16);
                $settings['accounting_recipients'] = array_values(array_filter($settings['accounting_recipients'], static fn($r) => $r['id'] !== $id));
                $this->fiscal->saveSettings($settings);
                return ['message' => 'Destinatário removido.', 'data' => ['recipients' => $settings['accounting_recipients']]];
            });
        }, 'email.recipient.delete');
    }

    public function remove_certificate(): void
    {
        $this->mutation('settings', function () { $this->fiscal->removeCertificate(); return ['message' => 'Certificado A1 local removido.', 'data' => ['reload' => true]]; }, 'certificate.remove', []);
    }

    public function municipality(): void
    {
        $this->mutation('settings', function () {
            $settings = $this->fiscal->settings();
            $this->operationContext += ['environment' => $settings['environment'], 'municipality_code' => $settings['provider']['municipality_code']];
            $result = (new ApiClient($settings['environment'], $this->service->certificate(null, $settings['environment']), null, fn(array $event) => $this->recordApi($event)))->municipality($settings['provider']['municipality_code']);
            return ['message' => 'Consulta municipal concluída. Confira a habilitação e os parâmetros retornados.', 'data' => ['municipality' => $result]];
        }, 'municipality.consult', []);
    }

    public function client($clientId): void
    {
        if (!$this->pagePermission('view')) { return; }
        try { $data = $this->fiscal->clientData($this->id($clientId)); }
        catch (Throwable $e) { show_error(html_escape($e->getMessage()), 403); return; }
        $data['title'] = 'NFS-e — ' . $data['client']->company;
        $this->load->view('nfse_nacional/client', $data);
    }

    public function save_profile($clientId): void
    {
        $this->mutation('edit', function () use ($clientId) {
            $input = $this->input->post('profile', false);
            if (!is_array($input)) { throw new InvalidArgumentException('Dados do perfil inválidos.'); }
            $this->fiscal->saveProfile($this->id($clientId), $input);
            return ['message' => 'Preferências fiscais do cliente salvas.', 'data' => []];
        }, 'client.profile.save', ['client_id' => $clientId]);
    }

    public function save_recipient($clientId, $recipientId = '0'): void
    {
        $this->mutation('edit', function () use ($clientId, $recipientId) {
            $input = $this->input->post('recipient', false);
            if (!is_array($input)) { throw new InvalidArgumentException('Dados do tomador inválidos.'); }
            $id = $this->fiscal->saveRecipient($this->id($clientId), $this->id($recipientId, true), $input);
            return ['message' => 'Tomador salvo.', 'data' => ['id' => $id, 'reload' => true]];
        }, 'recipient.save', ['client_id' => $clientId]);
    }

    public function delete_recipient($clientId, $recipientId): void
    {
        $this->mutation('edit', function () use ($clientId, $recipientId) {
            $this->fiscal->deleteRecipient($this->id($clientId), $this->id($recipientId));
            return ['message' => 'Tomador removido do cadastro. Os documentos já emitidos preservam seus dados.', 'data' => ['reload' => true]];
        }, 'recipient.delete', ['client_id' => $clientId]);
    }

    public function prepare($invoiceId): void
    {
        $this->mutation('create', function () use ($invoiceId) {
            $selection = $this->input->post('recipient_selection', false);
            if ($selection !== null && !is_string($selection)) { throw new InvalidArgumentException('Seleção do tomador inválida.'); }
            $id = $this->fiscal->prepare($this->id($invoiceId), $selection);
            return ['message' => 'Rascunho preparado.', 'data' => ['id' => $id, 'url' => admin_url('nfse_nacional/document/' . $id)]];
        }, 'draft.prepare', ['invoice_id' => $invoiceId]);
    }

    /** Read-only options for the native invoice form; every customer/invoice is reauthorized. */
    public function invoice_recipient_options(): void
    {
        if (!$this->pagePermission('view')) { return; }
        if (strtoupper($this->input->method()) !== 'GET') {
            $this->output->set_header('Allow: GET');
            $this->respond(['ok' => false, 'message' => 'Método não permitido.'], 405);
            return;
        }
        try {
            $clientId = $this->id($this->input->get('client_id'));
            $invoice = $this->input->get('invoice_id');
            $invoiceId = $invoice === null || $invoice === '' ? 0 : $this->id($invoice, true);
            $data = $this->fiscal->invoiceRecipientContext($clientId, $invoiceId);
            $automation = new \NfseNacional\Automation($this->fiscal);
            foreach ($data['options'] as &$option) { $option['automation_summary'] = $automation->summary($clientId, $option['value']); }
            unset($option);
            $this->respond(['ok' => true, 'data' => $data]);
        } catch (Throwable $error) {
            $this->respond(['ok' => false, 'message' => 'Não foi possível consultar os tomadores deste cliente. Verifique seu acesso e atualize a tela.'], 403);
        }
    }

    /** Refresh the private invoice panel after an action, without exposing fiscal XML or credentials. */
    public function invoice_context($invoiceId): void
    {
        if (!$this->pagePermission('view')) { return; }
        try {
            $context = $this->fiscal->invoiceContext($this->id($invoiceId));
            $html = $this->load->view('nfse_nacional/invoice_panel', $context, true);
            $this->respond(['ok' => true, 'data' => ['html' => $html]]);
        } catch (Throwable $error) {
            $this->respond(['ok' => false, 'message' => 'Não foi possível consultar a NFS-e desta fatura. Verifique o acesso e atualize a tela.'], 403);
        }
    }

    public function emit_invoice($invoiceId): void
    {
        $this->mutation('transmit', function () use ($invoiceId) {
            if (!nfse_nacional_can('create')) { throw new RuntimeException('Você não tem permissão para preparar a emissão a partir da fatura.'); }
            $selection = $this->input->post('recipient_selection', false);
            if ($selection !== null && !is_string($selection)) { throw new InvalidArgumentException('Seleção do tomador inválida.'); }
            $id = $this->fiscal->prepare($this->id($invoiceId), $selection);
            $this->preparedDocumentId = $id;
            $this->operationContext['document_id'] = $id;
            $doc = $this->service->transmit($id);
            return ['message' => $doc['delivery_warning'] ?? ($doc['status'] === 'authorized' ? 'NFS-e autorizada.' : 'Consulte o resultado da DPS.'),
                'data' => ['document' => $doc, 'url' => admin_url('nfse_nacional/document/' . $id)]];
        }, 'invoice.emit', ['invoice_id' => $invoiceId]);
    }

    public function document($id): void
    {
        if (!$this->pagePermission('view')) { return; }
        try {
            $id = $this->id($id); $doc = $this->fiscal->document($id);
            $draft = Nfse_nacional_model::decode($doc['draft_json']);
            $can = [];
            foreach (['view', 'create', 'edit', 'transmit', 'send', 'settings', 'substitute', 'cancel'] as $cap) { $can[$cap] = nfse_nacional_can($cap); }
            $host = $doc['environment'] === '1' ? 'www.nfse.gov.br' : 'www.producaorestrita.nfse.gov.br';
            $orphan = false;
            try {
                $invoice = $this->fiscal->invoice((int) $doc['invoice_id']); $client = $this->fiscal->client((int) $doc['client_id']);
                if ((int) $invoice->clientid !== (int) $doc['client_id']) { throw new RuntimeException('O cliente da fatura foi alterado após a criação do documento fiscal.'); }
            }
            catch (RuntimeException $error) {
                if (!is_admin()) { throw $error; }
                $orphan = true;
                $invoice = (object) ['id' => (int) $doc['invoice_id'], 'clientid' => (int) $doc['client_id'],
                    'subtotal' => $draft['values']['service_amount'], 'discount_total' => $draft['values']['unconditional_discount'], 'total' => $doc['service_amount']];
                $client = (object) ['userid' => (int) $doc['client_id'], 'company' => 'Arquivo fiscal — cadastro de origem indisponível'];
                $can['edit'] = $can['transmit'] = $can['create'] = false;
            }
            $needsReview = !$orphan && (!hash_equals($doc['invoice_fingerprint'], Nfse_nacional_model::fingerprint($invoice))
                || (\NfseNacional\Decimal::normalize($invoice->total) !== \NfseNacional\Decimal::subtract($invoice->subtotal, $invoice->discount_total ?? '0') && ($draft['invoice_values_acknowledged'] ?? '0') !== '1'));
            if (!$orphan && in_array((int) $invoice->status, [5, 6], true)) { $can['edit'] = $can['transmit'] = $can['create'] = false; }
            $replacement = array_key_exists('revision', $doc) ? $this->fiscal->replacementFor($id) : null;
            if ($replacement && (!empty($replacement['dps_id']) || (int) $replacement['attempts'] > 0)) { $can['send'] = false; }
            $publicDocument = $this->service->publicDocument($doc);
            $officialTax = $publicDocument['tax_summary']['iss'] ?? null;
            $data = ['title' => 'NFS-e — Rascunho #' . $id, 'document' => $publicDocument, 'draft' => $draft, 'invoice_values_review_required' => $needsReview,
                'official_tax_summary' => is_array($officialTax) && !empty($officialTax['official']) ? $officialTax : null,
                'replacement_document_id' => $replacement ? (int) $replacement['id'] : null, 'original_document_id' => (int) ($doc['replacement_of_id'] ?? 0),
                'can_recover_by_key' => in_array($doc['status'], ['uncertain', 'processing', 'rejected'], true) && (int) $doc['attempts'] > 0 && !empty($doc['dps_id']) && !empty($doc['request_enc']),
                'events' => $this->fiscal->events($id), 'recipients' => $orphan ? [] : $this->fiscal->recipientsForInvoice((int) $doc['client_id'], $invoice),
                'client' => $client, 'invoice' => $invoice, 'orphan_document' => $orphan,
                'danfe_status' => Danfe::diagnostics(), 'fiscal_status' => $this->service->fiscalStatus($doc),
                'can' => $can, 'consultation_url' => !empty($doc['access_key']) ? 'https://' . $host . '/ConsultaPublica/?tpc=1&chave=' . rawurlencode($doc['access_key']) : ''];
        } catch (Throwable $e) { show_error(html_escape($e->getMessage()), 403); return; }
        $this->load->view('nfse_nacional/document', $data);
    }

    public function save($id): void
    {
        $this->mutation('edit', function () use ($id) {
            $id = $this->id($id); $input = $this->input->post('draft', false); $hash = $this->input->post('draft_hash', false);
            if (!is_array($input) || !is_string($hash)) { throw new InvalidArgumentException('Rascunho inválido. Atualize a tela.'); }
            $doc = $this->fiscal->document($id);
            $flag = $this->input->post('send_after_authorized', false);
            $sendAfter = $flag === null ? (bool) $doc['send_after_authorized'] : $flag === '1';
            $recipient = $this->input->post('recipient_id', false);
            $selection = $this->input->post('recipient_selection', false);
            if ($selection !== null && !is_string($selection)) { throw new InvalidArgumentException('Seleção do tomador inválida.'); }
            $result = $this->service->saveDraft($id, $input, $hash, $sendAfter, $recipient ? $this->id($recipient) : null, $selection);
            return ['message' => 'Rascunho salvo.', 'data' => ['document' => $result]];
        }, 'draft.save', ['document_id' => $id]);
    }

    public function delete_draft($id): void
    {
        $this->mutation('edit', function () use ($id) {
            $clientId = $this->fiscal->deleteDraft($this->id($id));
            return ['message' => 'Rascunho local excluído.', 'data' => ['url' => admin_url('nfse_nacional/client/' . $clientId)]];
        }, 'draft.delete', ['document_id' => $id]);
    }

    public function validate($id): void
    {
        $this->mutation('transmit', function () use ($id) {
            return ['message' => 'Validação local concluída. A autorização será feita pelo ambiente nacional.', 'data' => ['document' => $this->service->validate($this->id($id))]];
        }, 'document.validate', ['document_id' => $id]);
    }

    public function transmit($id): void
    {
        $this->mutation('transmit', function () use ($id) {
            $doc = $this->service->transmit($this->id($id));
            $message = $doc['status'] === 'authorized' ? 'NFS-e autorizada. XML disponível.' : 'DPS consultada. Confira a situação antes de continuar.';
            return ['message' => $doc['delivery_warning'] ?? $doc['substitution_warning'] ?? $message, 'data' => ['document' => $doc, 'reload' => true]];
        }, 'document.transmit', ['document_id' => $id]);
    }

    public function reconcile($id): void
    {
        $this->mutation('view', function () use ($id) {
            $doc = $this->service->reconcile($this->id($id));
            $message = $doc['status'] === 'cancelled' ? 'Cancelamento fiscal confirmado. O DANFSe apresenta o carimbo de cancelamento.'
                : ($doc['status'] === 'authorized' ? 'Situação consultada. Autorização e XML disponíveis.' : ($doc['last_error'] ?: 'Consulta concluída.'));
            return ['message' => $message, 'data' => ['document' => $doc, 'reload' => true]];
        }, 'document.reconcile', ['document_id' => $id]);
    }

    public function recover_key($id): void
    {
        $this->mutation('view', function () use ($id) {
            $key = $this->requestFields(['access_key'], true)['access_key'];
            $doc = $this->service->recoverByKey($this->id($id), $key);
            return ['message' => $doc['substitution_warning'] ?? 'Autorização conferida. Chave e XML oficial disponíveis na emissão original.',
                'data' => ['document' => $doc, 'reload' => true]];
        }, 'document.recover_key', ['document_id' => $id]);
    }

    public function substitute($id): void
    {
        $this->mutation('substitute', function () use ($id) {
            $fields = $this->requestFields(['reason_code', 'reason'], true);
            $replacement = Substitution::prepare($this->fiscal, $this->id($id), $fields['reason_code'], $fields['reason']);
            return ['message' => 'Rascunho de substituição preparado. Revise os dados e a prévia antes de transmitir.',
                'data' => ['id' => $replacement, 'url' => admin_url('nfse_nacional/document/' . $replacement)]];
        }, 'document.substitution_prepare', ['document_id' => $id]);
    }

    public function fiscal_events($origin, $id): void
    {
        if (!$this->pagePermission('view')) { return; }
        try { $action = $this->lifecycleService()->page(Config::text($origin, 8), $this->id($id)); }
        catch (Throwable $error) { show_error(html_escape($error->getMessage()), 403); return; }
        $this->load->view('nfse_nacional/fiscal_events', ['title' => 'Eventos fiscais da NFS-e', 'fiscal_action' => $action]);
    }

    public function fiscal_event_submit($origin, $id): void
    {
        $this->submitFiscalEvent($origin, $id, false);
    }

    public function fiscal_event_retry($origin, $id): void
    {
        $this->submitFiscalEvent($origin, $id, true);
    }

    private function submitFiscalEvent($origin, $id, bool $retry): void
    {
        $this->mutation('view', function () use ($origin, $id, $retry) {
            $fields = $this->requestFields(['event_type', 'reason_code', 'reason'], true);
            $this->operationContext += ['event_type' => $fields['event_type']];
            $result = $this->lifecycleService()->submit(Config::text($origin, 8), $this->id($id), $fields['event_type'], $fields['reason_code'], $fields['reason'], $retry);
            return ['message' => $result['message'], 'data' => ['reload' => true, 'status' => $result['status']]];
        }, $retry ? 'fiscal_event.retry' : 'fiscal_event.submit');
    }

    public function fiscal_event_consult($origin, $id): void
    {
        $this->mutation('view', function () use ($origin, $id) {
            $type = $this->requestFields(['event_type'], true)['event_type'];
            $this->operationContext += ['event_type' => $type];
            $result = $this->lifecycleService()->consult(Config::text($origin, 8), $this->id($id), $type);
            return ['message' => $result['message'], 'data' => ['reload' => true, 'status' => $result['status']]];
        }, 'fiscal_event.consult');
    }

    public function fiscal_event_xml($origin, $id, $type): void
    {
        if (!$this->pagePermission('view')) { return; }
        $this->beginOperation('fiscal_event.download');
        try {
            $type = Config::text($type, 6); $id = $this->id($id);
            $xml = $this->lifecycleService()->eventXml(Config::text($origin, 8), $id, $type);
        } catch (Throwable $error) { $this->recordFailure($error, 422); show_error(html_escape($error->getMessage()), 422); return; }
        $this->finishOperation('info', 'XML do evento fiscal disponibilizado.', ['event_type' => $type, 'operation_status' => 'success', 'response_bytes' => strlen($xml)]);
        $this->output->set_content_type('application/xml')->set_header('Content-Disposition: attachment; filename="evento-' . $id . '-' . $type . '.xml"')->set_output($xml);
    }

    private function lifecycleService(): LifecycleService
    {
        return new LifecycleService($this->fiscal, fn($environment, $certificate): EventClient =>
            new EventClient($environment, $certificate, null, fn(array $event) => $this->recordApi($event)));
    }

    public function retry_same($id): void
    {
        $this->mutation('transmit', function () use ($id) {
            $doc = $this->service->retrySame($this->id($id));
            return ['message' => $doc['delivery_warning'] ?? 'DPS original consultada e processada. Nenhuma nova numeração foi criada.', 'data' => ['document' => $doc, 'reload' => true]];
        }, 'document.retry', ['document_id' => $id]);
    }

    public function send($id): void
    {
        $this->mutation('send', function () use ($id) {
            return ['message' => 'O servidor SMTP aceitou o envio do XML e do DANFSe para o destinatário.', 'data' => ['document' => $this->service->send($this->id($id)), 'reload' => true]];
        }, 'document.email', ['document_id' => $id]);
    }

    public function retry_email($id): void
    {
        $this->mutation('send', function () use ($id) {
            $doc = $this->service->retryEmail($this->id($id));
            return ['message' => 'Nova tentativa de envio aceita pelo servidor SMTP.', 'data' => ['document' => $doc, 'reload' => true]];
        }, 'document.email_retry', ['document_id' => $id]);
    }

    public function download($id, $kind = 'xml'): void
    {
        if (!$this->pagePermission('view')) { return; }
        if (!in_array($kind, ['xml', 'pdf'], true)) { show_404(); return; }
        $this->beginOperation('document.download_' . $kind, ['document_id' => $id]);
        try {
            $id = $this->id($id); $doc = $this->fiscal->document($id);
            $this->operationContext += ['environment' => $doc['environment'], 'invoice_id' => $doc['invoice_id']];
            $bytes = $kind === 'pdf' ? $this->service->pdf($id) : $this->service->xml($id);
        } catch (Throwable $e) {
            $this->finishOperation('error', 'Falha ao disponibilizar o documento fiscal.', ['operation_status' => 'error', 'exception_class' => get_class($e), 'http_status' => 422]);
            show_error(html_escape($e->getMessage()), 422); return;
        }
        $this->finishOperation('info', 'Documento fiscal disponibilizado para download.', ['operation_status' => 'success', 'response_bytes' => strlen($bytes), 'http_status' => 200]);
        $name = 'nfse-' . $id . '.' . $kind;
        $this->output->set_content_type($kind === 'pdf' ? 'application/pdf' : 'application/xml')
            ->set_header('Cache-Control: no-store, private, max-age=0')->set_header('Pragma: no-cache')
            ->set_header('X-Content-Type-Options: nosniff')
            ->set_header('Content-Disposition: attachment; filename="' . $name . '"')->set_output($bytes);
    }

    /** Preview is a read-only PDF of persisted data. It never creates or transmits a DPS. */
    public function preview($id): void
    {
        if (!$this->pagePermission('view')) { return; }
        $this->beginOperation('document.preview', ['document_id' => $id]);
        try {
            $id = $this->id($id);
            $doc = $this->fiscal->document($id);
            $this->operationContext += ['environment' => $doc['environment'], 'invoice_id' => $doc['invoice_id']];
            $bytes = $this->service->pdf($id);
        } catch (Throwable $error) {
            $this->finishOperation('error', 'Não foi possível gerar a prévia do DANFSe.', ['operation_status' => 'error', 'exception_class' => get_class($error), 'http_status' => 422]);
            show_error(html_escape($error->getMessage()), 422); return;
        }
        $this->finishOperation('info', 'Pré-visualização do DANFSe gerada a partir dos dados salvos.', ['operation_status' => 'success', 'response_bytes' => strlen($bytes), 'http_status' => 200]);
        $this->output->set_content_type('application/pdf')
            ->set_header('Cache-Control: no-store, private, max-age=0')->set_header('Pragma: no-cache')
            ->set_header('X-Content-Type-Options: nosniff')->set_header('X-Frame-Options: SAMEORIGIN')
            ->set_header('Content-Disposition: inline; filename="danfse-' . $id . '.pdf"')->set_output($bytes);
    }

    private function pagePermission(string $capability): bool
    {
        if (strtoupper($this->input->method()) !== 'GET') { show_error('Método não permitido.', 405); return false; }
        if (!nfse_nacional_can($capability)) { access_denied('NFS-e Nacional'); return false; }
        return true;
    }

    private function mutation(string $capability, callable $callback, string $operation, array $context = []): void
    {
        if (strtoupper($this->input->method()) !== 'POST') { $this->respond(['ok' => false, 'message' => 'Use POST para esta operação.'], 405); return; }
        if (!nfse_nacional_can($capability)) { $this->respond(['ok' => false, 'message' => 'Você não tem permissão para esta operação.'], 403); return; }
        if (!(bool) $this->config->item('csrf_protection')) { $this->respond(['ok' => false, 'message' => 'Ative a proteção CSRF nativa do Perfex antes de alterar ou transmitir documentos.'], 503); return; }
        $this->beginOperation($operation, $context);
        try {
            $result = $callback();
            $document = $result['data']['document'] ?? [];
            $status = $document['status'] ?? 'success';
            $uncertain = in_array($status, ['uncertain', 'processing'], true);
            $warning = ($operation === 'national.sync' && !empty($result['data']['run']['last_error'])) || $uncertain || $status === 'rejected' || !empty($document['delivery_warning']) || !empty($document['tax_summary']['ibpt_error'])
                || (str_starts_with($operation, 'registration.') && empty($result['data']['fields']))
                || ($operation === 'ibpt.refresh' && (($result['data']['ibpt_table']['status'] ?? '') !== 'current'
                    || in_array($result['data']['ibpt_table']['refresh_state'] ?? '', ['failed', 'throttled'], true)));
            $extra = ['http_status' => 200, 'operation_status' => $uncertain ? 'uncertain' : ($warning ? 'partial' : 'success')];
            foreach (['environment', 'invoice_id'] as $key) { if (isset($document[$key])) { $extra[$key] = $document[$key]; } }
            if (isset($document['id'])) { $extra['document_id'] = $document['id']; }
            elseif ($operation === 'draft.prepare' && isset($result['data']['id'])) { $extra['document_id'] = $result['data']['id']; }
            $this->finishOperation($warning ? 'warning' : 'info', $result['message'] ?? 'Operação concluída.', $extra);
            $this->respond(['ok' => true] + $result);
        }
        catch (ValidationException $e) {
            $this->recordFailure($e, 422, ['errors' => $e->issues]);
            $this->respond(['ok' => false, 'message' => 'Revise os campos indicados.', 'data' => ['errors' => $e->issues]], 422);
        }
        catch (ApiException $e) {
            $status = $e->isUncertain() ? 503 : 422;
            $this->recordFailure($e, $status, ['http_status' => $e->httpStatus(), 'errors' => $e->errors(), 'uncertain' => $e->isUncertain(), 'operation_status' => $e->isUncertain() ? 'uncertain' : 'error']);
            $this->respond(['ok' => false, 'message' => $e->getMessage(), 'data' => ['errors' => $e->errors(), 'uncertain' => $e->isUncertain()]], $status);
        }
        catch (InvalidArgumentException $e) { $this->recordFailure($e, 422); $this->respond(['ok' => false, 'message' => $e->getMessage()], 422); }
        catch (RuntimeException $e) {
            $status = in_array((int) $e->getCode(), [403, 404, 429], true) ? (int) $e->getCode() : 409;
            $this->recordFailure($e, $status);
            $this->respond(['ok' => false, 'message' => $e->getMessage()], $status);
        }
        catch (Throwable $e) {
            $this->finishOperation('error', 'Falha interna ao executar a operação.', ['http_status' => 500, 'operation_status' => 'error', 'exception_class' => get_class($e)]);
            log_message('error', 'NFS-e Nacional: falha interna ' . get_class($e));
            $this->respond(['ok' => false, 'message' => 'Não foi possível concluir a operação. Consulte a situação da nota antes de tentar novamente.'], 500);
        }
    }

    private function respond(array $data, int $status = 200): void
    {
        if ($this->operationRecorded && $this->correlationId !== null) {
            $data['data'] = $data['data'] ?? [];
            $data['data']['correlation_id'] = $this->correlationId;
            if (nfse_nacional_can('logs')) {
                $data['data']['log_url'] = admin_url('nfse_nacional/logs?correlation_id=' . $this->correlationId);
            }
            if (empty($data['ok'])) { $data['message'] .= ' Referência: ' . $this->correlationId . '.'; }
        }
        if (empty($data['ok']) && $this->preparedDocumentId !== null) {
            // Keep the draft reachable when the one-click action reports validation/rejection.
            $data['data'] = ($data['data'] ?? []) + ['document_id' => $this->preparedDocumentId,
                'url' => admin_url('nfse_nacional/document/' . $this->preparedDocumentId)];
        }
        $data['csrf'] = ['name' => $this->security->get_csrf_token_name(), 'hash' => $this->security->get_csrf_hash()];
        $this->output->set_status_header($status)->set_content_type('application/json', 'utf-8')->set_output(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function id($value, bool $allowZero = false): int
    {
        if (!is_scalar($value) || !preg_match('/^\d{1,12}$/D', (string) $value) || (!$allowZero && (int) $value < 1)) { throw new InvalidArgumentException('Identificador inválido.'); }
        return (int) $value;
    }

    /** Diagnostics are optional: a log failure must not repeat or block fiscal work. */
    private function logStore(): ?OperationalLog
    {
        if ($this->operationLog !== null) { return $this->operationLog; }
        if ($this->logFactory === null) { return null; }
        try { $this->operationLog = ($this->logFactory)(); }
        catch (Throwable $ignored) { $this->logFactory = null; }
        return $this->operationLog;
    }

    private function beginOperation(string $name, array $context = []): void
    {
        $this->operationName = $name;
        $this->operationContext = $context + ['method' => strtoupper($this->input->method()), 'service' => 'nfse_nacional'];
        $this->operationStarted = microtime(true);
        $this->operationRecorded = false;
        try { $this->correlationId = bin2hex(random_bytes(16)); }
        catch (Throwable $ignored) { $this->correlationId = null; }
        $this->recordOperation('info', 'Operação iniciada.', ['operation_status' => 'started', 'stage' => 'start']);
    }

    private function recordOperation(string $level, string $message, array $context = [], ?string $operation = null): void
    {
        try {
            $store = $this->logStore();
            if (!$store || $this->correlationId === null) { return; }
            $id = $store->record($level, $operation ?? $this->operationName, $message,
                ['correlation_id' => $this->correlationId] + $context + $this->operationContext);
            if ($id !== null) { $this->operationRecorded = true; }
        } catch (Throwable $ignored) { /* Preserve the result and never retry the operation here. */ }
    }

    private function finishOperation(string $level, string $message, array $context = []): void
    {
        $this->recordOperation($level, $message, $context + [
            'duration_ms' => round((microtime(true) - $this->operationStarted) * 1000, 2), 'stage' => 'finish',
        ]);
    }

    private function recordFailure(Throwable $error, int $status, array $context = []): void
    {
        $this->finishOperation('error', $error->getMessage(), $context + [
            'http_status' => $status, 'operation_status' => 'error', 'exception_class' => get_class($error),
        ]);
    }

    private function recordApi(array $event): void
    {
        $operation = $event['operation'] ?? 'api.request';
        if (!is_string($operation) || !array_key_exists($operation, $this->logOperations())) { $operation = 'api.request'; }
        unset($event['operation']);
        $status = $event['operation_status'] ?? 'error';
        $level = in_array($status, ['success', 'not_found'], true) ? 'info' : ($status === 'uncertain' ? 'warning' : 'error');
        $this->recordOperation($level, $status === 'success' ? 'Resposta recebida e validada.'
            : ($status === 'not_found' ? 'Ausência do documento confirmada pelo serviço.' : 'A consulta ao serviço exige verificação.'), $event + ['stage' => 'http'], $operation);
    }

    private function logFilters(): array
    {
        $filters = [];
        foreach (['date_from', 'date_to', 'level', 'operation', 'http_status', 'environment', 'search', 'correlation_id'] as $key) {
            $value = $this->input->get($key);
            if ($value === null || $value === '') { continue; }
            if (!is_string($value) || strlen($value) > 200) { throw new InvalidArgumentException('Filtro de logs inválido.'); }
            $filters[$key] = trim($value);
        }
        foreach (['date_from', 'date_to'] as $key) {
            if (empty($filters[$key])) { continue; }
            if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $filters[$key])) { throw new InvalidArgumentException('Informe as datas no formato ano-mês-dia.'); }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $filters[$key]);
            if (!$date || $date->format('Y-m-d') !== $filters[$key]) { throw new InvalidArgumentException('Informe as datas no formato ano-mês-dia.'); }
        }
        if (!empty($filters['date_from']) && !empty($filters['date_to']) && $filters['date_from'] > $filters['date_to']) {
            throw new InvalidArgumentException('A data inicial deve ser anterior ou igual à data final.');
        }
        $allowed = ['level' => ['debug', 'info', 'warning', 'error'], 'environment' => ['1', '2'], 'operation' => array_keys($this->logOperations())];
        foreach ($allowed as $key => $options) {
            if (!empty($filters[$key]) && !in_array($filters[$key], $options, true)) { throw new InvalidArgumentException('Filtro de logs inválido.'); }
        }
        if (isset($filters['http_status']) && !preg_match('/^(?:0|[1-5][0-9]{2})$/D', $filters['http_status'])) { throw new InvalidArgumentException('Código HTTP inválido.'); }
        if (!empty($filters['correlation_id']) && !preg_match('/^[a-f0-9]{32}$/D', $filters['correlation_id'])) { throw new InvalidArgumentException('Referência de diagnóstico inválida.'); }
        return $filters;
    }

    private function logOperations(): array
    {
        return [
            'municipality.consult' => 'Consulta do convênio municipal', 'api.municipality' => 'Resposta do convênio municipal',
            'registration.cnpj' => 'Consulta de CNPJ', 'registration.cep' => 'Consulta de CEP', 'registration.lookup' => 'Consulta cadastral',
            'ibpt.lookup' => 'Consulta dos tributos aproximados', 'ibpt.refresh' => 'Atualização da tabela IBPT',
            'ibpt.import' => 'Importação do arquivo IBPT',
            'settings.save' => 'Configurações e certificado A1', 'certificate.remove' => 'Remoção do certificado local',
            'client.profile.save' => 'Perfil fiscal do cliente', 'recipient.save' => 'Cadastro de tomador', 'recipient.delete' => 'Remoção de tomador',
            'draft.prepare' => 'Preparação do rascunho', 'draft.save' => 'Gravação do rascunho', 'draft.delete' => 'Exclusão do rascunho',
            'invoice.emit' => 'Emissão pela fatura', 'document.validate' => 'Validação da NFS-e', 'document.transmit' => 'Transmissão da NFS-e',
            'document.reconcile' => 'Consulta da situação fiscal', 'document.retry' => 'Reenvio da mesma DPS',
            'api.submit' => 'Resposta da transmissão', 'api.find_dps' => 'Resposta da consulta da DPS', 'api.fetch' => 'Obtenção da NFS-e', 'api.events' => 'Consulta dos eventos fiscais', 'api.request' => 'Requisição ao ambiente nacional',
            'document.email' => 'Envio por e-mail', 'document.email_retry' => 'Nova tentativa de e-mail',
            'document.preview' => 'Pré-visualização do DANFSe', 'document.download_pdf' => 'Download do DANFSe', 'document.download_xml' => 'Download do XML', 'logs.export' => 'Exportação dos logs',
            'national.sync' => 'Sincronização do acervo nacional', 'api.distribution' => 'Resposta da distribuição nacional',
            'national.preview' => 'Visualização de DANFSe nacional', 'national.download_pdf' => 'Download de DANFSe nacional', 'national.download_xml' => 'Download de XML nacional',
            'accounting.download' => 'Download do pacote mensal', 'accounting.send' => 'Envio do pacote mensal',
            'document.recover_key' => 'Recuperação da autorização pela chave',
            'document.substitution_prepare' => 'Preparação de substituição da NFS-e',
            'fiscal_event.submit' => 'Solicitação de evento fiscal', 'fiscal_event.retry' => 'Reenvio explícito do mesmo evento',
            'fiscal_event.consult' => 'Consulta do resultado de evento fiscal', 'fiscal_event.download' => 'Download do XML do evento',
            'api.event_submit' => 'Resposta do registro de evento', 'api.event_find' => 'Resposta da consulta de evento',
        ];
    }
}
