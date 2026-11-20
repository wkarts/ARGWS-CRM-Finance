<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
require dirname(__DIR__) . '/autoload.php';

use NfseNacional\Config;
use NfseNacional\Crypto;
use NfseNacional\NationalRepository;
use NfseNacional\NationalService;

$checks = 0; $nationalPrefix = 'national_a_'; $nationalTaxId = '11222333000181'; $nationalPermissions = ['report_package'=>true,'report_issued'=>true,'report_received'=>true,'report_export'=>true,'national_view' => true, 'national_sync' => true];
function nationalCheck(bool $ok, string $label): void { if (!$ok) { throw new RuntimeException('FALHOU: ' . $label); } ++$GLOBALS['checks']; }
function nationalReject(callable $operation, string $label): void {
    try { $operation(); } catch (Throwable $error) { nationalCheck(true, $label); return; }
    throw new RuntimeException('FALHOU: ' . $label);
}
function db_prefix(): string { return $GLOBALS['nationalPrefix']; }
function nfse_nacional_scope(): string { return hash('sha256', 'national-test:' . db_prefix()); }
function nfse_nacional_can(string $permission): bool { return $GLOBALS['nationalPermissions'][$permission] ?? false; }
function is_staff_logged_in(): bool { return true; }
function get_staff_user_id(): int { return 7; }
function get_option(string $name) { return $name === 'company_vat' ? $GLOBALS['nationalTaxId'] : ($GLOBALS['nationalOptions'][$name] ?? ''); }

final class NationalFixtureResult {
    public function __construct(private array $rows) {}
    public function row_array(): ?array { return $this->rows[0] ?? null; }
    public function result_array(): array { return $this->rows; }
}
/** Bounded CI query/transaction adapter; repository, parser, crypto and service are real. */
final class NationalFixtureDatabase {
    public array $tables = []; public bool $save_queries = true; public bool $db_debug = true;
    public ?int $failWriteAfter = null; public array $ddl = [];
    private array $conditions = []; private ?array $orGroup = null; private array $ordering = [];
    private ?array $selected = null; private ?int $take = null; private int $offset = 0;
    private ?array $snapshot = null; private bool $healthy = true; private int $lastId = 0;
    public function where(string $key, $value): self {
        if (!preg_match('/^([a-z_]+)(?:\s+(>=|<=|<|>|!=))?$/D', $key, $match)) { throw new LogicException('Unexpected condition: ' . $key); }
        $operator = $match[2] ?? '='; $column = $match[1];
        $this->conditions[] = static function ($row) use ($column, $operator, $value): bool {
            $left = (string) ($row[$column] ?? ''); $right = (string) $value;
            return match ($operator) { '=' => $left === $right, '!=' => $left !== $right, '>=' => $left >= $right, '<=' => $left <= $right, '>' => $left > $right, '<' => $left < $right };
        }; return $this;
    }
    public function where_in(string $key, array $values): self { $this->conditions[] = static fn($row) => in_array($row[$key], $values, true); return $this; }
    public function group_start(): self { if ($this->orGroup !== null) { throw new LogicException('Nested group'); } $this->orGroup = []; return $this; }
    public function like(string $key, string $value): self { return $this->or_like($key, $value); }
    public function or_like(string $key, string $value): self {
        $test = static fn($row) => mb_stripos((string) ($row[$key] ?? ''), $value, 0, 'UTF-8') !== false;
        if ($this->orGroup !== null) { $this->orGroup[] = $test; } else { $this->conditions[] = $test; } return $this;
    }
    public function group_end(): self { $tests = $this->orGroup; $this->orGroup = null; $this->conditions[] = static fn($row) => (bool) array_filter($tests, static fn($test) => $test($row)); return $this; }
    public function select(string $columns): self { $this->selected = explode(',', $columns); return $this; }
    public function order_by(string $key, string $direction): self { $this->ordering[] = [$key, $direction]; return $this; }
    public function limit(int $take, int $offset = 0): self { $this->take = $take; $this->offset = $offset; return $this; }
    private function matches(array $row): bool { foreach ($this->conditions as $condition) { if (!$condition($row)) { return false; } } return true; }
    private function reset(): void { $this->conditions = []; $this->orGroup = null; $this->ordering = []; $this->selected = null; $this->take = null; $this->offset = 0; }
    public function get(string $table): NationalFixtureResult {
        $rows = array_values(array_filter($this->tables[$table] ?? [], fn($row) => $this->matches($row)));
        $ordering = $this->ordering;
        if ($ordering) { usort($rows, static function ($a, $b) use ($ordering) { foreach ($ordering as [$key, $direction]) { $result = $a[$key] <=> $b[$key]; if ($result) { return $direction === 'desc' ? -$result : $result; } } return 0; }); }
        if ($this->take !== null) { $rows = array_slice($rows, $this->offset, $this->take); }
        if ($this->selected !== null) { $keys = array_flip($this->selected); $rows = array_map(static fn($row) => array_intersect_key($row, $keys), $rows); }
        $this->reset(); return new NationalFixtureResult($rows);
    }
    public function count_all_results(string $table): int { return count($this->get($table)->result_array()); }
    private function writable(): bool {
        if ($this->failWriteAfter !== null && $this->failWriteAfter-- <= 0) { $this->failWriteAfter = null; $this->healthy = false; $this->reset(); return false; } return true;
    }
    public function insert(string $table, array $row): bool {
        if (!$this->writable()) { return false; }
        if (!str_ends_with($table, '_sync')) { $this->lastId = max(array_column($this->tables[$table] ?? [], 'id') ?: [0]) + 1; $row['id'] = $this->lastId; }
        $this->tables[$table][] = $row; $this->reset(); return true;
    }
    public function insert_id(): int { return $this->lastId; }
    public function update(string $table, array $values): bool {
        if (!$this->writable()) { return false; }
        foreach ($this->tables[$table] as &$row) { if ($this->matches($row)) { $row = array_replace($row, $values); } } unset($row); $this->reset(); return true;
    }
    public function trans_begin(): bool { if ($this->snapshot !== null) { throw new LogicException('Nested transaction'); } $this->snapshot = $this->tables; $this->healthy = true; return true; }
    public function trans_status(): bool { return $this->healthy; }
    public function trans_commit(): bool { $this->snapshot = null; return true; }
    public function trans_rollback(): bool { $this->tables = $this->snapshot ?? $this->tables; $this->snapshot = null; $this->healthy = true; $this->reset(); return true; }
    public function query(string $sql): bool { $this->ddl[] = $sql; return true; }
}

final class NationalFixtureModel {
    public array $configuration; public array $row = []; public array $locks = []; public bool $locked = false;
    public function __construct(public NationalFixtureDatabase $db, string $taxId, string $pfx) {
        $this->configuration = Config::defaults(); $this->configuration['provider']['tax_id'] = $taxId;
        $this->configuration['use_local_certificate'] = '1';
        $this->row = ['certificate_enc' => $this->crypto()->encrypt(json_encode(['pfx' => base64_encode($pfx), 'password' => 'offline']))];
    }
    public function settings(): array { return $this->configuration; }
    public function table(string $suffix): string { if ($suffix !== 'documents') { throw new LogicException('Unexpected fixture table'); } return db_prefix() . 'nfse_nacional_documents'; }
    public function settingsRow(): array { return $this->row; }
    public function crypto(): Crypto { return new Crypto(str_repeat('test-national-key-', 3), nfse_nacional_scope()); }
    public function lock(string $key, callable $operation) {
        if ($this->locked) { throw new RuntimeException('Busy'); } $this->locked = true; $this->locks[] = $key;
        try { return $operation(); } finally { $this->locked = false; }
    }
}

function nationalPfx(string $taxId): string {
    $config = tempnam(sys_get_temp_dir(), 'nfse_national_a1_');
    try {
        file_put_contents($config, "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=OFFLINE\n");
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $config]);
        $csr = openssl_csr_new(['commonName' => 'NATIONAL OFFLINE:' . $taxId], $key, ['config' => $config, 'digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 1, ['config' => $config, 'digest_alg' => 'sha256']);
        $pfx = ''; if (!openssl_pkcs12_export($cert, $pfx, $key, 'offline')) { throw new RuntimeException('Fixture A1'); } return $pfx;
    } finally { unlink($config); }
}
function nationalNote(int $number, string $provider, string $recipient, string $env = '2', string $competence = '2026-11-10', string $issued = '2026-12-01T00:01:00-03:00'): array {
    $key = '2927408' . '2' . (strlen($provider) === 11 ? '1' : '2') . str_pad($provider, 14, '0', STR_PAD_LEFT) . str_pad((string) $number, 13, '0', STR_PAD_LEFT) . '2611' . '123456789' . '1';
    $dpsId = 'DPS2927408' . (strlen($provider) === 11 ? '1' : '2') . str_pad($provider, 14, '0', STR_PAD_LEFT) . '00001' . str_pad((string) $number, 15, '0', STR_PAD_LEFT);
    $providerTag = strlen($provider) === 11 ? 'CPF' : 'CNPJ'; $recipientTag = strlen($recipient) === 11 ? 'CPF' : 'CNPJ';
    $xml = '<NFSe xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.01"><infNFSe Id="NFS' . $key . '">'
        . '<nNFSe>' . $number . '</nNFSe><cStat>100</cStat><dhProc>2026-12-01T00:02:00-03:00</dhProc><nDFSe>123</nDFSe>'
        . '<emit><' . $providerTag . '>' . $provider . '</' . $providerTag . '><xNome>Prestador ' . $number . '</xNome><enderNac><cMun>2927408</cMun><UF>BA</UF></enderNac></emit>'
        . '<DPS versao="1.01"><infDPS Id="' . $dpsId . '"><tpAmb>' . $env . '</tpAmb><dhEmi>' . $issued . '</dhEmi><serie>1</serie><nDPS>' . $number . '</nDPS><dCompet>' . $competence . '</dCompet><tpEmit>1</tpEmit><cLocEmi>2927408</cLocEmi>'
        . '<prest><' . $providerTag . '>' . $provider . '</' . $providerTag . '><regTrib><opSimpNac>1</opSimpNac><regEspTrib>0</regEspTrib></regTrib></prest>'
        . '<toma><' . $recipientTag . '>' . $recipient . '</' . $recipientTag . '><xNome>Tomador ' . $number . '</xNome></toma>'
        . '<serv><locPrest><cLocPrestacao>2927408</cLocPrestacao></locPrest><cServ><cTribNac>010101</cTribNac><xDescServ>Serviço fictício offline</xDescServ></cServ></serv>'
        . '<valores><vServPrest><vServ>100.00</vServ></vServPrest><trib><tribMun><tribISSQN>1</tribISSQN><tpRetISSQN>1</tpRetISSQN></tribMun><totTrib><indTotTrib>0</indTotTrib></totTrib></trib></valores>'
        . '</infDPS></DPS></infNFSe></NFSe>';
    return ['nsu' => (string) $number, 'type' => 'nfse', 'access_key' => $key, 'xml' => $xml, 'event_type' => '', 'generated_at' => '2026-12-01T00:02:00-03:00'];
}
function nationalEvent(string $key, int $nsu, string $type = '101101', string $env = '2'): array {
    $xml = '<evento xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.01"><infEvento Id="EVT' . $key . $type . '001"><verAplic>OFFLINE</verAplic><ambGer>2</ambGer><nSeqEvento>1</nSeqEvento><dhProc>2026-12-02T10:01:00-03:00</dhProc><nDFSe>42</nDFSe><pedRegEvento versao="1.01"><infPedReg Id="PRE' . $key . $type . '"><tpAmb>' . $env . '</tpAmb><verAplic>OFFLINE</verAplic><dhEvento>2026-12-02T10:00:00-03:00</dhEvento><CNPJAutor>11222333000181</CNPJAutor><chNFSe>' . $key . '</chNFSe><e' . $type . '><xDesc>EVENTO SINTÉTICO</xDesc><cMotivo>1</cMotivo><xMotivo>Teste offline</xMotivo></e' . $type . '></infPedReg></pedRegEvento></infEvento><Signature xmlns="http://www.w3.org/2000/09/xmldsig#"><SignatureValue>RklYVFVSRSBPRkZMSU5F</SignatureValue></Signature></evento>';
    return ['nsu' => (string) $nsu, 'type' => 'event', 'access_key' => $key, 'xml' => $xml, 'event_type' => $type, 'generated_at' => '2026-12-02T10:01:00-03:00'];
}
function nationalBatch(array $items, string $last = '0', bool $hasMore = true): array {
    return ['items' => $items, 'next_nsu' => $items ? end($items)['nsu'] : $last, 'has_more' => $items ? $hasMore : false,
        'empty' => !$items, 'retry_after' => $items ? 0 : 3600];
}

$pfx = nationalPfx($nationalTaxId); $database = new NationalFixtureDatabase(); $model = new NationalFixtureModel($database, $nationalTaxId, $pfx);
$database->tables[db_prefix() . 'nfse_nacional_documents'] = [['id' => 51, 'status' => 'draft', 'draft_json' => 'UNCHANGED-LOCAL']];
$localSnapshot = $database->tables[db_prefix() . 'nfse_nacional_documents'];
$clock = time(); $queue = []; $calls = [];
$factory = static function ($environment, $certificate) use (&$queue, &$calls) {
    return new class($environment, $queue, $calls) {
        public function __construct(private string $environment, private array &$queue, private array &$calls) {}
        public function distribute($nsu, $taxId): array { $this->calls[] = [$this->environment, $nsu, $taxId]; $reply = array_shift($this->queue); if ($reply instanceof Throwable) { throw $reply; } return $reply; }
    };
};
$service = new NationalService($model, $factory, static fn() => $GLOBALS['clock']); $repository = new NationalRepository($model);
$issued = nationalNote(1, $nationalTaxId, '11144477735');
$received = nationalNote(2, '11444777000161', $nationalTaxId);
$both = nationalNote(3, $nationalTaxId, $nationalTaxId);
$unrelated = nationalNote(4, '11444777000161', '11144477735');
$queue[] = nationalBatch([$issued, $received, $both, $unrelated]);
$result = $service->sync('2');
nationalCheck($result['imported'] === 3 && $result['ignored'] === 1 && $result['state']['last_nsu'] === '4', 'lote classifica participantes exatos e avança cursor após persistência');
nationalCheck($calls === [['2', '0', $nationalTaxId]], 'consulta usa emitente atual e cursor inicial sem alterar ambiente');
nationalCheck($repository->list('issued', ['environment' => '2'])['total'] === 2 && $repository->list('received', ['environment' => '2'])['total'] === 2, 'documento com ambos os papéis entra nas duas consultas');
nationalCheck($repository->list('both', ['environment' => '2'])['total'] === 3, 'consulta combinada não duplica documento de ambos os papéis');
$list = $repository->list('both', ['environment' => '2'], 2, 1);
nationalCheck(count($list['rows']) === 1 && $list['page'] === 2 && $list['pages'] === 3 && $list['total'] === 3, 'paginação limita linhas sem perder total');
nationalCheck(!isset($list['rows'][0]['xml_enc'], $list['rows'][0]['fiscal_meta_json'], $list['rows'][0]['issuer_tax_id']), 'listagem não expõe payload protegido ou metadados internos');
nationalCheck($repository->list('received', ['environment' => '2', 'search' => 'Prestador 2'])['total'] === 1, 'pesquisa filtra nome dentro do mesmo emitente e ambiente');
nationalCheck(count($repository->exportRows('2', '2026-11', 'both')) === 3 && count($repository->exportRows('2', '2026-12', 'both')) === 0, 'exportação mensal usa competência mesmo quando emissão está no mês seguinte');
nationalCheck($repository->list('both', ['environment' => '2', 'date_from' => '2026-12-01', 'date_to' => '2026-12-01'])['total'] === 3, 'filtros de datas continuam baseados na emissão');
nationalCheck($repository->xml(1) === $issued['xml'], 'download restitui XML original byte a byte');
$stored = $repository->document(1);
nationalCheck(str_starts_with($stored['xml_enc'], 'v1:') && !str_contains($stored['xml_enc'], '<NFSe'), 'XML é armazenado somente cifrado');
nationalCheck(str_starts_with($service->pdf(1), '%PDF-'), 'DANFSe é gerado pelo motor real a partir do XML arquivado');
nationalCheck($database->tables[db_prefix() . 'nfse_nacional_documents'] === $localSnapshot, 'sincronização não modifica documentos locais');

$duplicate = $issued; $duplicate['nsu'] = '5'; $queue[] = nationalBatch([$duplicate]); $result = $service->sync('2');
nationalCheck($result['imported'] === 0 && $result['ignored'] === 1 && $repository->state('2')['last_nsu'] === '5', 'repetição da mesma chave/XML é idempotente sem travar NSU novo');
$legacyCancellation = nationalEvent($issued['access_key'], 6);
$legacyCancellation['xml'] = str_replace(['versao="1.01"', 'nDFSe'], ['versao="1.00"', 'nDFe'], $legacyCancellation['xml']);
$queue[] = nationalBatch([$legacyCancellation]); $result = $service->sync('2');
nationalCheck($result['events'] === 1 && $result['updated'] === 1 && $repository->document(1)['status'] === 'cancelled', 'evento confirmado cancela nota arquivada');
nationalCheck($model->crypto()->decrypt($repository->document(1)['cancellation_event_enc']) === $legacyCancellation['xml'],
    'evento histórico arquivado criptografado sem reescrever o XML assinado');
nationalCheck($repository->xml(1) === $issued['xml'] && str_starts_with($service->pdf(1), '%PDF-'), 'cancelamento preserva XML e gera DANFSe a partir de evidência validada');
$pendingNote = nationalNote(8, $nationalTaxId, '11144477735');
$queue[] = nationalBatch([nationalEvent($pendingNote['access_key'], 7)]); $service->sync('2');
$queue[] = nationalBatch([$pendingNote]); $service->sync('2');
nationalCheck($repository->document(4)['status'] === 'cancelled', 'evento que chega antes da nota é aplicado quando a nota é arquivada');
$queue[] = nationalBatch([nationalEvent($received['access_key'], 9, '101103')]); $service->sync('2');
nationalCheck($repository->document(2)['status'] === 'authorized', 'pedido de cancelamento não equivale a cancelamento confirmado');

$beforeFailure = $database->tables[db_prefix() . 'nfse_nacional_national_documents'];
$malformed = nationalNote(11, $nationalTaxId, '11144477735'); $malformed['xml'] = '<NFSe/>';
$queue[] = nationalBatch([nationalNote(10, $nationalTaxId, '11144477735'), $malformed]);
nationalReject(fn() => $service->sync('2'), 'XML inválido bloqueia o lote integral');
nationalCheck($repository->state('2')['last_nsu'] === '9' && $database->tables[db_prefix() . 'nfse_nacional_national_documents'] === $beforeFailure, 'falha XML não arquiva parte do lote nem avança cursor');
$queue[] = nationalBatch([nationalNote(10, $nationalTaxId, '11144477735'), nationalNote(11, $nationalTaxId, '11144477735')]);
$database->failWriteAfter = 1;
nationalReject(fn() => $service->sync('2'), 'falha de banco entre documentos desfaz transação');
nationalCheck($repository->state('2')['last_nsu'] === '9' && $database->tables[db_prefix() . 'nfse_nacional_national_documents'] === $beforeFailure, 'rollback restaura acervo e cursor juntos');
$changed = $issued; $changed['nsu'] = '10'; $changed['xml'] = str_replace('Serviço fictício offline', 'XML divergente', $changed['xml']);
$queue[] = nationalBatch([$changed]); nationalReject(fn() => $service->sync('2'), 'mesma chave com XML divergente não sobrescreve original');
nationalCheck($repository->xml(1) === $issued['xml'] && $repository->state('2')['last_nsu'] === '9', 'conflito preserva original e cursor');
$outOfOrder = $received; $outOfOrder['nsu'] = '9'; $queue[] = nationalBatch([$outOfOrder]);
nationalReject(fn() => $service->sync('2'), 'NSU repetido antes do último cursor é recusado');
$wrongEnvironment = nationalNote(10, $nationalTaxId, '11144477735', '1'); $queue[] = nationalBatch([$wrongEnvironment]);
nationalReject(fn() => $service->sync('2'), 'XML de produção não contamina produção restrita');
$queue[] = new RuntimeException('PRIVATE_UPSTREAM_CANARY'); nationalReject(fn() => $service->sync('2'), 'falha externa mantém sincronização não concluída');
nationalCheck(!str_contains(json_encode($repository->state('2')), 'PRIVATE_UPSTREAM_CANARY'), 'erro persistido não contém corpo externo ou segredo');

$queue[] = ['items' => [], 'next_nsu' => '9', 'has_more' => false, 'empty' => false, 'retry_after' => 7200];
$limited = $service->sync('2'); $callCount = count($calls);
nationalCheck($limited['throttled'] && $limited['state']['last_nsu'] === '9'
    && strtotime($limited['next_sync_at'] . ' UTC') === $clock + 7200, '429 preserva cursor e respeita Retry-After superior a uma hora');
$service->sync('2'); nationalCheck(count($calls) === $callCount, 'intervalo persistido bloqueia nova chamada externa');
$clock += 7201; $queue[] = nationalBatch([], '9'); $finished = $service->sync('2');
nationalCheck(!$finished['has_more'] && strtotime($finished['next_sync_at'] . ' UTC') >= $clock + 3600 && $finished['state']['last_nsu'] === '9', 'fim confirmado aguarda uma hora e não avança cursor');

$production = nationalNote(1, $nationalTaxId, '11144477735', '1'); $queue[] = nationalBatch([$production]); $service->sync('1');
nationalCheck($repository->list('both', ['environment' => '1'])['total'] === 1 && $repository->state('1')['last_nsu'] === '1', 'produção tem cursor e acervo independentes');
nationalCheck($repository->list('both', ['environment' => '2'])['total'] === 4, 'sincronizar produção preserva documentos de testes');

$nationalPermissions['national_view'] = false;
nationalReject(fn() => $repository->xml(1), 'download exige permissão de acervo nacional');
nationalReject(fn() => $repository->list('both'), 'consulta exige permissão de acervo nacional');
$nationalPermissions['national_view'] = true; $nationalPermissions['national_sync'] = false;
nationalReject(fn() => $service->sync('1'), 'sincronização exige permissão própria');
$nationalPermissions['national_sync'] = true;
$model->configuration['provider']['tax_id'] = '11444777000161';
nationalReject(fn() => $repository->document(1), 'CPF/CNPJ em parâmetros divergente da empresa ativa é recusado');
$model->configuration['provider']['tax_id'] = $nationalTaxId;
$nationalPrefix = 'national_b_';
nationalReject(fn() => $repository->document(1), 'objeto antigo não acessa dados após troca de empresa');
$otherModel = new NationalFixtureModel($database, $nationalTaxId, $pfx); $otherRepository = new NationalRepository($otherModel);
nationalReject(fn() => $otherRepository->document(1), 'ID de documento da empresa A é invisível na empresa B');
nationalCheck($otherRepository->list('both', ['environment' => '2'])['total'] === 0, 'empresa B possui acervo independente mesmo com CNPJ igual');
$nationalPrefix = 'national_a_';
$tampered = $repository->document(1); $database->tables[db_prefix() . 'nfse_nacional_national_documents'][0]['xml_hash'] = str_repeat('0', 64);
nationalReject(fn() => $repository->xml(1), 'download detecta hash adulterado');
$database->tables[db_prefix() . 'nfse_nacional_national_documents'][0] = $tampered;
$database->tables[db_prefix() . 'nfse_nacional_national_documents'][0]['cancellation_event_enc'] = $model->crypto()->encrypt(nationalEvent($received['access_key'], 10)['xml']);
nationalReject(fn() => $service->pdf(1), 'DANFSe cancelado rejeita evento de outra chave');
$database->tables[db_prefix() . 'nfse_nacional_national_documents'][0] = $tampered;
nationalReject(fn() => $repository->exportRows('2', '2026-13', 'both'), 'mês inválido é recusado');
nationalReject(fn() => $repository->list('all', []), 'direção arbitrária é recusada');
nationalCheck(array_keys(NationalRepository::schema()) === ['national_documents', 'national_events', 'national_sync'], 'migração limita-se às tabelas próprias do acervo');
nationalCheck(count($model->locks) > 0 && !array_filter($model->locks, static fn($key) => !str_starts_with($key, 'national:' . $GLOBALS['nationalTaxId'] . ':')),
    'trava de sincronização inclui emitente e ambiente, sob o escopo do modelo');

// A short HTTP 429 pause is not the one-hour confirmed-empty policy.
$cursorBefore = $repository->state('2')['last_nsu'];
$repository->failure('2', $nationalTaxId, 'Teste de intervalo expirado', gmdate('Y-m-d H:i:s', $clock - 1), gmdate('Y-m-d H:i:s', $clock));
$queue[] = ['items'=>[], 'next_nsu'=>$cursorBefore, 'has_more'=>false, 'empty'=>false, 'retry_after'=>60];
$shortPause = $service->sync('2');
nationalCheck($shortPause['throttled'] && strtotime($shortPause['next_sync_at'].' UTC') === $clock + 60 && $shortPause['state']['last_nsu'] === $cursorBefore, '429 de 60 segundos não se transforma em uma hora ou avança o NSU');
$clock += 61; $queue[] = ['items'=>[], 'next_nsu'=>$cursorBefore, 'has_more'=>false, 'empty'=>true, 'retry_after'=>3600];
$emptyAfterPause = $service->sync('2');
nationalCheck(!$emptyAfterPause['throttled'] && strtotime($emptyAfterPause['next_sync_at'].' UTC') === $clock + 3600, 'ausência confirmada continua respeitando uma hora');

echo 'OK: ' . $checks . " verificações do acervo nacional. Sem API, emissão ou banco externo.\n";
