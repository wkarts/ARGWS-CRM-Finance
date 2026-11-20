<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Migration runs in its own process so the real helper can load without the
// service harness's authentication stubs. No database connection is opened.
if (($argv[1] ?? '') === '--migration') {
    define('BASEPATH', __DIR__);
    class App_module_migration {}
    final class SubstitutionMigrationResult {
        public function __construct(private array $rows) {}
        public function result_array(): array { return $this->rows; }
    }
    final class SubstitutionMigrationDatabase {
        public string $database = 'offline'; public string $hostname = 'offline';
        public array $columns = ['id', 'invoice_id', 'environment'];
        public array $indexes = ['PRIMARY', 'invoice_environment'];
        public array $rows = [['id' => 1, 'invoice_id' => 41, 'environment' => '2', 'response_enc' => 'UNCHANGED-XML'],
            ['id' => 2, 'invoice_id' => 42, 'environment' => '1', 'response_enc' => 'UNCHANGED-XML-2']];
        public array $queries = []; public ?string $failOn = null;
        public function field_exists(string $field, string $table): bool { return in_array($field, $this->columns, true); }
        public function query(string $sql) {
            $this->queries[] = $sql;
            if ($this->failOn !== null && str_contains($sql, $this->failOn)) { $this->failOn = null; return false; }
            if (str_starts_with($sql, 'SHOW INDEX')) { return new SubstitutionMigrationResult(array_map(static fn($key) => ['Key_name' => $key], $this->indexes)); }
            if (preg_match('/ ADD `(revision|replacement_of_id)` /', $sql, $m)) {
                if (in_array($m[1], $this->columns, true)) { throw new RuntimeException('Repeated column'); }
                $this->columns[] = $m[1];
                foreach ($this->rows as &$row) { $row[$m[1]] = $m[1] === 'revision' ? 0 : null; } unset($row); return true;
            }
            if (preg_match('/ ADD UNIQUE KEY `([^`]+)`/', $sql, $m)) {
                if (in_array($m[1], $this->indexes, true)) { throw new RuntimeException('Repeated index'); }
                $this->indexes[] = $m[1]; return true;
            }
            if (str_contains($sql, ' DROP INDEX `invoice_environment`')) {
                if (!in_array('invoice_environment_revision', $this->indexes, true) || !in_array('replacement_of_id', $this->indexes, true)) { throw new RuntimeException('Unprotected upgrade'); }
                $this->indexes = array_values(array_diff($this->indexes, ['invoice_environment'])); return true;
            }
            if (str_starts_with($sql, 'CREATE TABLE IF NOT EXISTS `migration_sub_nfse_nacional_fiscal_requests`')) { return true; }
            throw new LogicException('Unexpected migration SQL');
        }
    }
    function db_prefix(): string { return 'migration_sub_'; }
    function &get_instance() { return $GLOBALS['substitutionMigrationCi']; }
    $substitutionMigrationCi = (object) ['db' => new SubstitutionMigrationDatabase()];
    require dirname(__DIR__) . '/migrations/070_version_070.php';
    $db = $substitutionMigrationCi->db; $before = $db->rows; $migration = new Migration_Version_070();
    $migration->up();
    if (count($db->rows) !== 2 || $db->rows[0]['response_enc'] !== $before[0]['response_enc']
        || $db->rows[1]['response_enc'] !== $before[1]['response_enc'] || $db->rows[0]['revision'] !== 0 || $db->rows[0]['replacement_of_id'] !== null
        || !in_array('invoice_environment_revision', $db->indexes, true) || !in_array('replacement_of_id', $db->indexes, true)
        || in_array('invoice_environment', $db->indexes, true)) { throw new RuntimeException('Migration data/index mismatch'); }
    $after = $db->rows; $queryCount = count($db->queries); $migration->up();
    if ($db->rows !== $after || array_filter(array_slice($db->queries, $queryCount), static fn($q) => str_starts_with($q, 'ALTER'))) { throw new RuntimeException('Migration not idempotent'); }
    foreach (['ADD UNIQUE KEY `invoice_environment_revision`', 'ADD UNIQUE KEY `replacement_of_id`', 'DROP INDEX `invoice_environment`'] as $failure) {
        $substitutionMigrationCi->db = $db = new SubstitutionMigrationDatabase(); $db->failOn = $failure; $failed = false;
        try { $migration->up(); } catch (RuntimeException $expected) { $failed = true; }
        if (!$failed || !in_array('invoice_environment', $db->indexes, true) || $db->rows[0]['response_enc'] !== 'UNCHANGED-XML') { throw new RuntimeException('Interrupted migration lost protection/data'); }
        $migration->up();
        if (in_array('invoice_environment', $db->indexes, true) || count($db->rows) !== 2 || $db->rows[0]['revision'] !== 0) { throw new RuntimeException('Migration did not resume safely'); }
    }
    echo "OK: migration idempotent; interrupted index upgrades preserve rows and uniqueness.\n";
    exit;
}

// Existing service, API, signing, parser and certificate fixtures remain real.
ob_start(); try { require __DIR__ . '/service_test.php'; } finally { ob_end_clean(); }
$checks = 0;

use NfseNacional\ApiClient;
use NfseNacional\ApiException;
use NfseNacional\Certificate;
use NfseNacional\Config;
use NfseNacional\Crypto;
use NfseNacional\DpsBuilder;
use NfseNacional\FiscalEvent;
use NfseNacional\NfseDocument;
use NfseNacional\Service;
use NfseNacional\Substitution;
use NfseNacional\XmlSigner;

function is_admin(): bool { return true; }
function admin_url(string $path = ''): string { return '/admin/' . $path; }
function substitutionCheck(bool $condition, string $label): void {
    if (!$condition) { throw new RuntimeException('FALHOU: ' . $label); } ++$GLOBALS['checks'];
}
function substitutionRefuses(callable $operation, string $label, ?callable $inspect = null): Throwable {
    try { $operation(); } catch (Throwable $error) { substitutionCheck($inspect === null || $inspect($error), $label); return $error; }
    throw new RuntimeException('FALHOU: ' . $label);
}

final class SubstitutionResult {
    public function __construct(private array $rows) {}
    public function row_array(): ?array { return $this->rows[0] ?? null; }
    public function result_array(): array { return $this->rows; }
}
/** Minimal CI persistence adapter; production model prepare/reserve/version lookup are exercised. */
final class SubstitutionDatabase {
    public array $tables = []; public bool $save_queries = false; public bool $db_debug = false;
    public ?int $failWriteAfter = null;
    private array $where = []; private array $order = []; private ?int $limit = null; private ?array $columns = null;
    private int $lastId = 0; private ?array $snapshot = null; private bool $healthy = true;
    public function select(string $columns): self { $this->columns = explode(',', $columns); return $this; }
    public function where(string $column, $value): self {
        if (!preg_match('/^([a-z_]+)(?:\s+(!=))?$/D', $column, $m)) { throw new LogicException('Unsupported condition'); }
        $this->where[] = [$m[1], $m[2] ?? '=', $value]; return $this;
    }
    public function order_by(string $column, string $direction): self { $this->order[] = [$column, $direction]; return $this; }
    public function limit(int $limit): self { $this->limit = $limit; return $this; }
    public function table_exists(string $table): bool { return isset($this->tables[$table]); }
    private function match(array $row): bool {
        foreach ($this->where as [$column, $op, $value]) { if (((string) ($row[$column] ?? '') === (string) $value) !== ($op === '=')) { return false; } } return true;
    }
    private function reset(): void { $this->where = []; $this->order = []; $this->limit = null; $this->columns = null; }
    public function get(string $table): SubstitutionResult {
        $rows = array_values(array_filter($this->tables[$table] ?? [], fn($row) => $this->match($row))); $order = $this->order;
        if ($order) { usort($rows, static function ($a, $b) use ($order) { foreach ($order as [$col, $dir]) { $cmp = $a[$col] <=> $b[$col]; if ($cmp) { return $dir === 'desc' ? -$cmp : $cmp; } } return 0; }); }
        if ($this->limit !== null) { $rows = array_slice($rows, 0, $this->limit); }
        if ($this->columns !== null) { $keys = array_flip($this->columns); $rows = array_map(static fn($row) => array_intersect_key($row, $keys), $rows); }
        $this->reset(); return new SubstitutionResult($rows);
    }
    private function writable(): bool {
        if ($this->failWriteAfter !== null && $this->failWriteAfter-- <= 0) { $this->failWriteAfter = null; $this->healthy = false; $this->reset(); return false; } return true;
    }
    public function insert(string $table, array $row): bool {
        if (!$this->writable()) { return false; }
        if (str_ends_with($table, '_documents')) {
            $row += ['revision' => 0, 'replacement_of_id' => null, 'dps_id' => null, 'dps_series' => null, 'dps_number' => null, 'request_enc' => null,
                'response_enc' => null, 'cancellation_event_enc' => null, 'access_key' => null, 'nfse_number' => null, 'attempts' => 0, 'email_status' => 'pending', 'last_error' => null, 'fiscal_meta_json' => null];
            foreach ($this->tables[$table] ?? [] as $old) {
                if (((int) $old['invoice_id'] === (int) $row['invoice_id'] && $old['environment'] === $row['environment'] && (int) $old['revision'] === (int) $row['revision'])
                    || ($row['replacement_of_id'] !== null && $row['replacement_of_id'] === $old['replacement_of_id'])) { $this->healthy = false; return false; }
            }
        }
        $this->lastId = max(array_column($this->tables[$table] ?? [], 'id') ?: [0]) + 1; $row['id'] = $this->lastId;
        $this->tables[$table][] = $row; $this->reset(); return true;
    }
    public function insert_id(): int { return $this->lastId; }
    public function update(string $table, array $changes): bool {
        if (!$this->writable()) { return false; }
        foreach ($this->tables[$table] as &$row) { if ($this->match($row)) { $row = array_replace($row, $changes); } } unset($row); $this->reset(); return true;
    }
    public function trans_begin(): bool { if ($this->snapshot !== null) { throw new LogicException('Nested transaction'); } $this->snapshot = $this->tables; $this->healthy = true; return true; }
    public function trans_status(): bool { return $this->healthy; }
    public function trans_commit(): bool { $this->snapshot = null; return true; }
    public function trans_rollback(): bool { $this->tables = $this->snapshot ?? $this->tables; $this->snapshot = null; $this->healthy = true; $this->reset(); return true; }
    public function query(string $sql, array $params = []) {
        if (preg_match('/^INSERT IGNORE INTO `([^`]+)` \(scope,last_number\)/', $sql, $m)) {
            if (!$this->writable()) { return false; }
            foreach ($this->tables[$m[1]] ?? [] as $row) { if ($row['scope'] === $params[0]) { return true; } }
            $this->tables[$m[1]][] = ['scope' => $params[0], 'last_number' => $params[1]]; return true;
        }
        if (preg_match('/^SELECT last_number FROM `([^`]+)` WHERE scope=\? FOR UPDATE/', $sql, $m)) { return $this->where('scope', $params[0])->get($m[1]); }
        throw new LogicException('Unexpected persistence SQL');
    }
}

final class SubstitutionModel extends Nfse_nacional_model {
    public $db; public array $configuration; public array $locks = []; public array $activeLocks = [];
    public array $invoices; private Crypto $cipher; private array $configurationRow;
    public function __construct(array $draft, string $pfx) {
        $this->db = new SubstitutionDatabase(); $this->cipher = new Crypto(str_repeat('substitution-secret-', 3), 'offline-substitution');
        $this->configuration = Config::defaults(); $this->configuration['provider'] = $draft['provider'];
        $this->configuration['environment'] = $draft['environment']; $this->configuration['production_enabled'] = '1'; $this->configuration['use_local_certificate'] = '1';
        $this->configurationRow = ['certificate_enc' => $this->cipher->encrypt(self::json(['pfx' => base64_encode($pfx), 'password' => 'only-in-memory']))];
        $this->invoices = [41 => (object) ['id' => 41, 'clientid' => 7, 'currency' => 1, 'status' => 1, 'date' => $draft['competence'],
            'subtotal' => '100.00', 'discount_total' => '0.00', 'total' => '100.00', 'items' => [['description' => 'Serviço offline', 'qty' => '1', 'rate' => '100.00']]]];
        $this->db->tables[db_prefix() . 'currencies'] = [['id' => 1, 'name' => 'BRL']];
        $this->db->tables[db_prefix() . 'invoices'] = [(array) $this->invoices[41]];
        $this->db->tables[$this->table('documents')] = []; $this->db->tables[$this->table('events')] = [];
        $this->db->tables[db_prefix() . 'nfse_nacional_fiscal_requests'] = [];
        $this->db->insert($this->table('documents'), ['invoice_id' => 41, 'client_id' => 7, 'environment' => $draft['environment'], 'status' => 'draft',
            'draft_json' => self::json($draft), 'draft_hash' => hash('sha256', self::json($draft)), 'invoice_fingerprint' => self::fingerprint($this->invoices[41]),
            'service_amount' => '100.00', 'send_after_authorized' => 0, 'recipient_email' => 'offline@example.invalid', 'created_at' => self::now()]);
    }
    public function table(string $suffix): string { return db_prefix() . 'nfse_nacional_' . $suffix; }
    public function crypto(): Crypto { return $this->cipher; }
    public function settings(): array { return $this->configuration; }
    public function settingsRow(): array { return $this->configurationRow; }
    public function invoice(int $id): object { if (!isset($this->invoices[$id])) { throw new RuntimeException('Unknown invoice'); } return $this->invoices[$id]; }
    public function client(int $id): object { if ($id !== 7) { throw new RuntimeException('Unknown client'); } return (object) ['userid' => 7, 'company' => 'Offline']; }
    public function lock(string $name, callable $operation) {
        if (isset($this->activeLocks[$name])) { throw new RuntimeException('Busy'); }
        $this->activeLocks[$name] = true; $this->locks[] = $name;
        try { return $operation(); } finally { unset($this->activeLocks[$name]); }
    }
}

function substitutionResponse(string $signedDps, int $number): array {
    $response = authorizedResponse($signedDps); $xml = ApiClient::decodeXml($response);
    $key = str_pad((string) $number, 50, '1', STR_PAD_LEFT);
    $xml = str_replace('Id="NFS' . str_repeat('1', 50) . '"', 'Id="NFS' . $key . '"', $xml);
    $xml = str_replace('<nNFSe>123</nNFSe>', '<nNFSe>' . $number . '</nNFSe>', $xml);
    $note = new NfseDocument($xml); $response['tipoAmbiente'] = (int) $note->environment();
    $response['chaveAcesso'] = $key; $response['nfseXmlGZipB64'] = base64_encode(gzencode($xml)); return $response;
}
function substitutionEvent(string $key, string $environment, string $type = '105102'): string {
    return '<evento xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.01"><infEvento Id="EVT' . $key . $type . '001"><verAplic>OFFLINE</verAplic><ambGer>2</ambGer><nSeqEvento>1</nSeqEvento><dhProc>2026-11-11T12:01:00-03:00</dhProc><nDFSe>42</nDFSe><pedRegEvento versao="1.01"><infPedReg Id="PRE' . $key . $type . '"><tpAmb>' . $environment . '</tpAmb><verAplic>OFFLINE</verAplic><dhEvento>2026-11-11T12:00:00-03:00</dhEvento><CNPJAutor>11222333000181</CNPJAutor><chNFSe>' . $key . '</chNFSe><e' . $type . '><xDesc>EVENTO OFFLINE</xDesc><cMotivo>1</cMotivo><xMotivo>Teste de substituição</xMotivo></e' . $type . '></infPedReg></pedRegEvento></infEvento><Signature xmlns="http://www.w3.org/2000/09/xmldsig#"><SignatureValue>T0ZGTElORQ==</SignatureValue></Signature></evento>';
}
function substitutionFixture(array $draft, string $pfx, string $eventMode = 'confirmed'): array {
    $model = new SubstitutionModel($draft, $pfx); $calls = (object) ['posts' => [], 'gets' => [], 'mode' => 'authorize', 'event_mode' => $eventMode, 'saved' => null];
    $service = new Service($model, static fn($environment, $certificate) => new ApiClient($environment, $certificate,
        static function ($method, $url, $body) use ($environment, $model, $calls): array {
            if ($method === 'POST') {
                $signed = gzdecode(base64_decode(json_decode($body, true)['dpsXmlGZipB64'])); $calls->posts[] = $signed;
                if (count($calls->posts) > 1) {
                    $source = $model->document(1);
                    substitutionCheck(isset($model->activeLocks['fiscal:' . $environment . ':' . $source['access_key']], $model->activeLocks['document:2']), 'replacement POST holds source fiscal lock and child document lock');
                }
                $calls->saved = substitutionResponse($signed, 100 + count($calls->posts));
                if ($calls->mode === 'timeout') { throw new RuntimeException('Synthetic timeout after POST'); }
                if ($calls->mode === 'reject') { return ['status' => 400, 'body' => json_encode(['tipoAmbiente' => (int) $environment, 'erros' => [['codigo' => 'E0055', 'descricao' => 'Rejeição sintética de substituição']]])]; }
                return ['status' => 201, 'body' => json_encode($calls->saved)];
            }
            $calls->gets[] = $url;
            if (str_contains($url, '/eventos')) {
                if ($calls->event_mode === 'failure') { return ['status' => 500, 'body' => '{"message":"synthetic offline error"}']; }
                if ($calls->event_mode === 'absent') { return ['status' => 404, 'body' => json_encode(['tipoAmbiente' => (int) $environment, 'erro' => ['codigo' => 'E404', 'descricao' => 'Nenhum evento encontrado para a NFS-e']])]; }
                $source = $model->document(1); $key = $calls->event_mode === 'wrong_key' ? str_repeat('2', 50) : $source['access_key'];
                $type = $calls->event_mode === 'request_only' ? '101103' : '105102';
                return ['status' => 200, 'body' => json_encode(['tipoAmbiente' => (int) $environment, 'eventoXmlGZipB64' => base64_encode(gzencode(substitutionEvent($key, $environment, $type)))])];
            }
            if ($calls->mode === 'recover') { return ['status' => 200, 'body' => json_encode($calls->saved)]; }
            return ['status' => 404, 'body' => json_encode(['tipoAmbiente' => (int) $environment, 'erro' => ['codigo' => 'E0001', 'descricao' => 'DPS não encontrada']])];
        }));
    $service->transmit(1); return [$model, $service, $calls];
}

$permissions = array_fill_keys(['view', 'create', 'edit', 'transmit', 'substitute'], true);
$reason = 'Correção da descrição da prestação do serviço.';
foreach (['1', '2'] as $environment) {
    $environmentDraft = $draft; $environmentDraft['environment'] = $environment;
    [$model, $service, $calls] = substitutionFixture($environmentDraft, $pfx);
    $original = $model->document(1); $originalXml = $service->xml(1); $invoice = clone $model->invoice(41);
    $replacementId = Substitution::prepare($model, 1, '99', $reason); $replacement = $model->document($replacementId);
    substitutionCheck($replacementId === 2 && $replacement['revision'] === 1 && $replacement['replacement_of_id'] === 1
        && $replacement['invoice_id'] === 41 && $replacement['client_id'] === 7 && $replacement['environment'] === $environment, 'new revision retains original invoice/client/environment ' . $environment);
    substitutionCheck(count($model->invoices) === 1 && $model->invoice(41) == $invoice && count($model->db->tables[db_prefix() . 'invoices']) === 1, 'substitution never duplicates or edits native invoice ' . $environment);
    substitutionCheck($model->document(1) === $original && $service->xml(1) === $originalXml && $replacement['dps_id'] === null
        && $replacement['request_enc'] === null && $replacement['response_enc'] === null, 'preparation preserves source XML and reserves no child DPS ' . $environment);
    substitutionCheck(Substitution::prepare($model, 1, '99', $reason) === 2 && count($model->db->tables[$model->table('documents')]) === 2, 'repeated preparation returns same child ' . $environment);
    substitutionCheck($model->prepare(41) === 2 && $model->invoiceContext(41)['current_document']['id'] === 2, 'invoice actions open latest version ' . $environment);
    $childDraft = $model::decode($replacement['draft_json']); $childDraft['issued_at'] = '2026-11-11T12:00:00-03:00';
    $signed = XmlSigner::sign((new DpsBuilder())->build($childDraft, '1', '2'), Certificate::fromPkcs12($pfx, 'only-in-memory', '11222333000181'));
    $xp = new DOMXPath(XmlSigner::loadSafe($signed)); $xp->registerNamespace('n', DpsBuilder::NS);
    substitutionCheck((new DpsBuilder())->validateSchema($signed, $environment) === [] && XmlSigner::verify($signed), 'signed substitution validates official XSD ' . $environment);
    substitutionCheck($xp->evaluate('string(/n:DPS/n:infDPS/n:subst/n:chSubstda)') === $original['access_key']
        && $xp->evaluate('string(/n:DPS/n:infDPS/n:subst/n:cMotivo)') === '99' && $xp->evaluate('string(/n:DPS/n:infDPS/n:subst/n:xMotivo)') === $reason,
        'substitution reference and reason are in proper schema group ' . $environment);
    $service->saveDraft(2, ['service' => ['description' => 'Descrição corrigida no rascunho substituto'],
        'substitution' => ['access_key' => str_repeat('9', 50), 'reason_code' => '99', 'reason' => $reason]], $replacement['draft_hash'], false);
    substitutionCheck($model::decode($model->document(2)['draft_json'])['substitution']['access_key'] === $original['access_key'], 'editing the reason cannot redirect the original fiscal key ' . $environment);
    $authorized = $service->transmit(2); $child = $model->document(2);
    substitutionCheck($authorized['status'] === 'authorized' && $model->document(1)['status'] === 'cancelled' && count($calls->posts) === 2, 'new note is authorized and original cancelled only after registered event ' . $environment);
    substitutionCheck($child['dps_id'] !== $original['dps_id'] && $child['dps_number'] === '2' && $original['dps_number'] === '1'
        && $child['access_key'] !== $original['access_key'], 'replacement consumes new sequential DPS/key exactly once ' . $environment);
    substitutionCheck($service->xml(1) === $originalXml && $model->document(1)['request_enc'] === $original['request_enc']
        && $model->document(1)['draft_json'] === $original['draft_json'], 'authorized source XML/request/draft stay immutable ' . $environment);
    $service->transmit(2); substitutionCheck(count($calls->posts) === 2, 'repeated emission does not resubmit authorized replacement ' . $environment);
    substitutionCheck($service->fiscalStatus($model->document(1))['is_cancelled'] && $service->fiscalStatus($child)['is_authorized'], 'fiscal badges require original event and distinct child authorization ' . $environment);
    $third = Substitution::prepare($model, 2, '99', $reason); $thirdRow = $model->document($third);
    substitutionCheck($third === 3 && $thirdRow['revision'] === 2 && $thirdRow['replacement_of_id'] === 2
        && $model->prepare(41) === 3 && count($model->invoices) === 1 && $model->document(2) === $child,
        'next substitution links latest authorized revision and preserves prior versions ' . $environment);
}

foreach (['failure', 'absent', 'request_only', 'wrong_key'] as $eventMode) {
    [$model, $service, $calls] = substitutionFixture($draft, $pfx, $eventMode); $sourceXml = $service->xml(1);
    Substitution::prepare($model, 1, '99', $reason); $result = $service->transmit(2);
    substitutionCheck($result['status'] === 'authorized' && isset($result['substitution_warning']) && $model->document(2)['status'] === 'authorized', 'event ' . $eventMode . ' does not downgrade new authorization');
    substitutionCheck($model->document(1)['status'] === 'authorized' && $service->xml(1) === $sourceXml
        && empty($model->document(1)['cancellation_event_enc']), 'event ' . $eventMode . ' cannot claim original is cancelled');
    $service->transmit(2); substitutionCheck(count($calls->posts) === 2, 'event ' . $eventMode . ' never repeats replacement POST');
}

foreach (['reject', 'timeout'] as $mode) {
    [$model, $service, $calls] = substitutionFixture($draft, $pfx); $source = $model->document(1);
    Substitution::prepare($model, 1, '99', $reason); $calls->mode = $mode;
    substitutionRefuses(fn() => $service->transmit(2), 'replacement ' . $mode . ' surfaces failure', static fn($error) => $error instanceof ApiException);
    substitutionCheck($model->document(1) === $source && $model->document(2)['status'] === ($mode === 'timeout' ? 'uncertain' : 'rejected'), 'replacement ' . $mode . ' leaves source entirely unchanged');
    if ($mode === 'timeout') {
        $stable = $model->document(2)['dps_id']; $service->transmit(2);
        substitutionCheck(count($calls->posts) === 2 && $model->document(2)['dps_id'] === $stable, 'uncertain replacement is queried without reserving/reposting');
        $calls->mode = 'recover'; $result = $service->reconcile(2);
        substitutionCheck($result['status'] === 'authorized' && count($calls->posts) === 2 && $model->document(1)['status'] === 'cancelled', 'replacement recovery preserves DPS and obtains source event without POST');
    }
}

[$retryGuardModel, $retryGuardService, $retryGuardCalls] = substitutionFixture($draft, $pfx);
Substitution::prepare($retryGuardModel, 1, '99', $reason); $retryGuardCalls->mode = 'timeout';
substitutionRefuses(fn() => $retryGuardService->transmit(2), 'prepare an uncertain replacement for retry permission gate');
$permissions['send'] = true;
substitutionRefuses(fn() => $retryGuardService->send(1), 'original cannot be emailed while replacement transmission is pending', static fn($error) => str_contains($error->getMessage(), 'substituição'));
substitutionCheck($retryGuardModel->document(1)['email_status'] === 'pending', 'blocked source email does not create an SMTP attempt');
$permissions['send'] = false;
$sourceLock = 'fiscal:2:' . $retryGuardModel->document(1)['access_key']; $retryGuardModel->activeLocks[$sourceLock] = true;
$priorGets = count($retryGuardCalls->gets);
substitutionRefuses(fn() => $retryGuardService->retrySame(2), 'source cancellation lock prevents concurrent replacement retry');
substitutionCheck(count($retryGuardCalls->gets) === $priorGets && count($retryGuardCalls->posts) === 2, 'busy source lock prevents network operations');
unset($retryGuardModel->activeLocks[$sourceLock]);
$permissions['substitute'] = false;
substitutionRefuses(fn() => $retryGuardService->retrySame(2), 'explicit resend rechecks substitution permission before POST');
substitutionCheck(count($retryGuardCalls->posts) === 2, 'removed substitution permission cannot resend a pending replacement');
$permissions['substitute'] = true;
foreach (['processing', 'uncertain', 'registered'] as $pendingCancellation) {
    $retryGuardModel->db->tables[db_prefix() . 'nfse_nacional_fiscal_requests'] = [['issuer_tax_id' => $draft['provider']['tax_id'], 'environment' => '2',
        'access_key' => $retryGuardModel->document(1)['access_key'], 'event_type' => '101101', 'status' => $pendingCancellation]];
    substitutionRefuses(fn() => $retryGuardService->retrySame(2), 'explicit resend blocks source cancellation ' . $pendingCancellation);
    substitutionCheck(count($retryGuardCalls->posts) === 2, 'source cancellation ' . $pendingCancellation . ' makes no replacement POST');
}
$permissions['substitute'] = false; $retryGuardCalls->mode = 'recover';
$recoveredExisting = $retryGuardService->retrySame(2);
substitutionCheck($recoveredExisting['status'] === 'authorized' && count($retryGuardCalls->posts) === 2,
    'GET recovery of an existing replacement remains possible without permission for another POST');
$permissions['substitute'] = true;

[$model, $service, $calls] = substitutionFixture($draft, $pfx); Substitution::prepare($model, 1, '99', $reason);
$source = $model->document(1); $child = $model->document(2); $childDraft = $model::decode($child['draft_json']); $beforeCalls = count($calls->posts);
foreach (['provider' => static function (&$doc, &$d) { $d['provider']['tax_id'] = '11444777000161'; },
    'environment' => static function (&$doc, &$d) { $doc['environment'] = $d['environment'] = '1'; },
    'recipient' => static function (&$doc, &$d) { $d['recipient']['tax_id'] = '11144477735'; },
    'competence' => static function (&$doc, &$d) { $d['competence'] = '2026-10-01'; },
    'invoice' => static function (&$doc, &$d) { $doc['invoice_id'] = 42; },
    'key' => static function (&$doc, &$d) { $d['substitution']['access_key'] = str_repeat('2', 50); },
    'Simples value' => static function (&$doc, &$d) { $d['values']['service_amount'] = '101.00'; }] as $label => $mutate) {
    $changed = $child; $changedDraft = $childDraft; $mutate($changed, $changedDraft);
    substitutionRefuses(fn() => Substitution::assertSource($model, $changed, $changedDraft), 'immutable substitution field rejected: ' . $label);
}
substitutionCheck(count($calls->posts) === $beforeCalls && $model->document(1) === $source, 'invalid substitutions perform no network writes or source changes');
$requestTable = db_prefix() . 'nfse_nacional_fiscal_requests';
foreach (['processing', 'uncertain', 'registered'] as $status) {
    $model->db->tables[$requestTable] = [['issuer_tax_id' => $draft['provider']['tax_id'], 'environment' => '2', 'access_key' => $source['access_key'], 'event_type' => '101101', 'status' => $status]];
    substitutionRefuses(fn() => Substitution::assertSource($model, $child, $childDraft), 'concurrent cancellation ' . $status . ' blocks substitution');
}
$model->db->tables[$requestTable] = [];
$permissions['substitute'] = false;
substitutionRefuses(fn() => Substitution::prepare($model, 1, '99', $reason), 'preparation requires substitution permission even if child exists');
substitutionRefuses(fn() => $service->transmit(2), 'transmission rechecks substitution permission');
$permissions['substitute'] = true;

$normalDraft = $draft; $normalDraft['provider']['simple_status'] = '1'; $normalDraft['provider']['simple_apportionment'] = '';
$normalDraft['values']['iss_rate'] = '2.00';
[$normalModel, $normalService, $normalCalls] = substitutionFixture($normalDraft, $pfx);
Substitution::prepare($normalModel, 1, '99', $reason); $normalChild = $normalModel->document(2); $normalReplacement = $normalModel::decode($normalChild['draft_json']);
foreach (['subitem' => ['national_code' => '020101'], 'municipal code' => ['municipal_code' => '999'], 'municipality' => ['municipality_code' => '2927408']] as $label => $change) {
    $changed = $normalReplacement; $changed['service'] = array_replace($changed['service'], $change);
    substitutionRefuses(fn() => Substitution::assertSource($normalModel, $normalChild, $changed), 'normal regime preserves E0060 ' . $label);
}
$changed = $normalReplacement; $changed['service']['national_code'] = '010199';
Substitution::assertSource($normalModel, $normalChild, $changed);
substitutionCheck(true, 'E0060 compares four-digit subitem instead of blocking every national desdobramento');

// Source restrictions use original authorized XML, never a mutable draft copy.
$forgedSnapshot = $normalModel::decode($normalModel->document(1)['draft_json']);
$forgedSnapshot['recipient']['tax_id'] = '11144477735'; $normalModel->updateDocument(1, ['draft_json' => $normalModel::json($forgedSnapshot)]);
$changed = $normalReplacement; $changed['recipient']['tax_id'] = '11144477735';
substitutionRefuses(fn() => Substitution::assertSource($normalModel, $normalChild, $changed), 'E0058 reads authorized XML even when draft snapshot differs');

[$rollbackModel, $rollbackService] = substitutionFixture($draft, $pfx); $rollbackTables = $rollbackModel->db->tables;
$rollbackModel->db->failWriteAfter = 1;
substitutionRefuses(fn() => Substitution::prepare($rollbackModel, 1, '99', $reason), 'failed event insert rolls back child preparation');
substitutionCheck($rollbackModel->db->tables === $rollbackTables && $rollbackModel->replacementFor(1) === null, 'rollback preserves source and creates no orphan child');
$rollbackModel->configuration['provider']['tax_id'] = '11444777000161';
substitutionRefuses(fn() => Substitution::prepare($rollbackModel, 1, '99', $reason), 'changed current issuer cannot clone historical source');

$process = proc_open([PHP_BINARY, '-n', __FILE__, '--migration'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$migrationOutput = stream_get_contents($pipes[1]); fclose($pipes[1]); $migrationErrors = stream_get_contents($pipes[2]); fclose($pipes[2]);
substitutionCheck(proc_close($process) === 0 && str_contains($migrationOutput, 'OK: migration idempotent'), 'real migration is idempotent, preserves rows and resumes interrupted uniqueness upgrade: ' . $migrationErrors . $migrationOutput);

echo 'OK: ' . $checks . " verificações da substituição e migração. Model/Service/assinatura/XSD reais; sem banco, SEFIN, fatura nova ou SMTP externos.\n";
