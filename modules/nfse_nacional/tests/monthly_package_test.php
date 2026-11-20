<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Reuse the real national/service fixtures; their baseline output is suppressed.
ob_start(); try { require __DIR__ . '/national_service_test.php'; } finally { ob_end_clean(); }
$checks = 0;

use NfseNacional\FiscalEvent;
use NfseNacional\MonthlyDelivery;
use NfseNacional\MonthlyPackage;
use NfseNacional\NfseDocument;
use NfseNacional\ZipBuilder;

function monthlyReadZip(string $bytes): array {
    $offset = 0; $files = [];
    while (substr($bytes, $offset, 4) === "PK\x03\x04") {
        $header = unpack('Vsignature/vversion/vflags/vmethod/vtime/vdate/Vcrc/Vpacked/Vsize/vname/vextra', substr($bytes, $offset, 30));
        $name = substr($bytes, $offset + 30, $header['name']); $offset += 30 + $header['name'] + $header['extra'];
        $content = substr($bytes, $offset, $header['packed']); $offset += $header['packed'];
        if ($header['method'] === 8) { $content = gzinflate($content); }
        nationalCheck(is_string($content) && strlen($content) === $header['size'] && sprintf('%u', crc32($content)) === sprintf('%u', $header['crc']), 'ZIP entry CRC/size: ' . basename($name));
        nationalCheck(($header['flags'] & 0x0800) !== 0, 'ZIP UTF-8 flag: ' . basename($name));
        $files[$name] = $content;
    }
    nationalCheck(substr($bytes, $offset, 4) === "PK\x01\x02" && substr($bytes, -22, 4) === "PK\x05\x06", 'ZIP contains central directory and end record');
    return $files;
}
function monthlyLocalRow(array $item, int $id, string $status = 'authorized', ?string $eventXml = null): array {
    $note = new NfseDocument($item['xml']); $meta = ['processed_at' => $note->text('dhProc')];
    if ($eventXml !== null) {
        $event = FiscalEvent::fromXml($eventXml, $note->accessKey(), $note->environment());
        $meta += ['cancellation_confirmed' => true, 'cancellation_event_id' => $event['id'], 'cancelled_at' => $event['processed_at'], 'cancellation_record_number' => $event['record_number']];
    }
    return ['id' => $id, 'environment' => $note->environment(), 'status' => $status, 'access_key' => $note->accessKey(), 'dps_id' => $note->dpsId(),
        'nfse_number' => $note->number(), 'draft_json' => json_encode(['competence' => $note->dpsText('dCompet')]),
        'response_enc' => $GLOBALS['model']->crypto()->encrypt($item['xml']), 'fiscal_meta_json' => json_encode($meta),
        'cancellation_event_enc' => $eventXml === null ? null : $GLOBALS['model']->crypto()->encrypt($eventXml)];
}

$filters = ['environment' => '2', 'month' => '2026-11', 'direction' => 'both', 'format' => 'both'];
$localOnly = nationalNote(13, $nationalTaxId, '11144477735');
$localOnly['xml'] = str_replace('<xNome>Prestador 13</xNome>', '<xNome>=HYPERLINK("https://example.invalid")</xNome>', $localOnly['xml']);
$database->tables[$model->table('documents')][] = monthlyLocalRow($issued, 52);
$database->tables[$model->table('documents')][] = monthlyLocalRow($localOnly, 53);
$localCancellation = nationalEvent($both['access_key'], 14)['xml'];
$database->tables[$model->table('documents')][] = monthlyLocalRow($both, 54, 'cancelled', $localCancellation);
$package = new MonthlyPackage($model); $report = $package->report($filters);
nationalCheck($report['total'] === 5 && $report['national'] === 4 && $report['local'] === 1, 'monthly package merges national/local without duplicating access key');
nationalCheck($report['issued'] === 4 && $report['received'] === 2 && $report['cancelled'] === 3 && $report['missing_competence'] === 0, 'report counts both roles and confirmed cancellation without date fallback');
nationalCheck($package->report($filters)['fingerprint'] === $report['fingerprint'], 'unchanged archive yields stable confirmation fingerprint');
$bundle = $package->build($filters, $report['fingerprint']); $files = monthlyReadZip($bundle['bytes']);
nationalCheck($bundle['count'] === 5 && $bundle['sha256'] === hash('sha256', $bundle['bytes']) && str_ends_with($bundle['filename'], '.zip'), 'bundle metadata matches generated ZIP');
nationalCheck(isset($files['Emitidas/NFSe-' . $issued['access_key'] . '.xml']) && $files['Emitidas/NFSe-' . $issued['access_key'] . '.xml'] === $issued['xml'], 'package preserves original authorized XML');
nationalCheck(isset($files['Emitidas_e_Recebidas/NFSe-' . $both['access_key'] . '.xml'])
    && !isset($files['Emitidas/NFSe-' . $both['access_key'] . '.xml'], $files['Recebidas/NFSe-' . $both['access_key'] . '.xml']), 'both-role note has one copy in a dedicated folder');
nationalCheck($files['Emitidas_e_Recebidas/Eventos/Cancelamento-' . $both['access_key'] . '.xml'] === $localCancellation, 'confirmed local cancellation is preserved when ADN original takes precedence');
nationalCheck(str_contains($files['manifesto.csv'], "'=HYPERLINK") && str_starts_with($files['manifesto.csv'], "\xEF\xBB\xBF"), 'manifest protects spreadsheet formulas and preserves UTF-8');
foreach ($files as $path => $contents) { if (str_ends_with($path, '.pdf')) { nationalCheck(str_starts_with($contents, '%PDF-'), 'real DANFSe in ZIP: ' . basename($path)); } }
$zipPath = tempnam(sys_get_temp_dir(), 'monthly_zip_');
try {
    file_put_contents($zipPath, $bundle['bytes']);
    $process = proc_open(['unzip', '-t', $zipPath], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
    nationalCheck(proc_close($process) === 0 && str_contains($output, 'No errors detected'), 'independent unzip verifies all ZIP structures/CRC');
} finally { unlink($zipPath); }
$xmlFilters = array_replace($filters, ['direction' => 'received', 'format' => 'xml']); $xmlReport = $package->report($xmlFilters);
$xmlBundle = $package->build($xmlFilters, $xmlReport['fingerprint']); $xmlFiles = monthlyReadZip($xmlBundle['bytes']);
nationalCheck($xmlReport['total'] === 2 && !array_filter(array_keys($xmlFiles), static fn($path) => str_ends_with($path, '.pdf')), 'received/XML selection does not create PDFs');
nationalCheck($package->report(array_replace($filters, ['month' => '2026-12']))['total'] === 0, 'competence differs from issue date in package filters');
$emptyFilters = array_replace($filters, ['month' => '2026-12']);
nationalReject(fn() => $package->build($emptyFilters, $package->report($emptyFilters)['fingerprint']), 'empty package is rejected');
$oldFingerprint = $report['fingerprint'];
$newLocal = nationalNote(15, $nationalTaxId, '11144477735'); $database->tables[$model->table('documents')][] = monthlyLocalRow($newLocal, 55);
nationalReject(fn() => $package->build($filters, $oldFingerprint), 'new note invalidates prior composition confirmation');
array_pop($database->tables[$model->table('documents')]);
$invalidLocal = monthlyLocalRow($newLocal, 55, 'cancelled'); $database->tables[$model->table('documents')][] = $invalidLocal;
nationalReject(fn() => $package->report($filters), 'cancelled local note without event prevents misleading package');
array_pop($database->tables[$model->table('documents')]);
$nationalPermissions['national_view'] = false; nationalReject(fn() => $package->report($filters), 'monthly report requires national archive permission'); $nationalPermissions['national_view'] = true;
$nationalTable = db_prefix() . 'nfse_nacional_national_documents';
$savedNational = $database->tables[$nationalTable];
try {
    $changedDuringBuild = false;
    $concurrentPackage = new MonthlyPackage($model, null, static function () use ($database, $nationalTable, &$changedDuringBuild): string {
        if (!$changedDuringBuild) {
            $changedDuringBuild = true;
            // A later note changes after the composition was confirmed, before it is rendered.
            foreach ($database->tables[$nationalTable] as &$row) { if ($row['id'] === 2) { $row['status'] = 'cancelled'; } } unset($row);
        }
        return '%PDF-offline-concurrency-fixture';
    });
    nationalReject(fn() => $concurrentPackage->build($filters, $report['fingerprint']), 'concurrent status change aborts the entire package');
} finally { $database->tables[$nationalTable] = $savedNational; }
try {
    $many = [];
    for ($i = 0; $i < 1001; ++$i) {
        $item = nationalNote(1000 + $i, $nationalTaxId, '11144477735'); $note = new NfseDocument($item['xml']);
        $many[] = array_replace($savedNational[0], ['id' => 1000 + $i, 'access_key' => $note->accessKey(), 'dps_id' => $note->dpsId(),
            'status' => 'authorized', 'xml_hash' => hash('sha256', $item['xml']), 'xml_enc' => $model->crypto()->encrypt($item['xml']),
            'cancellation_event_enc' => null, 'fiscal_meta_json' => '{}']);
    }
    $database->tables[$nationalTable] = $many;
    nationalReject(fn() => $package->report($filters), 'more than one thousand notes are rejected before package generation without truncating');
} finally { $database->tables[$nationalTable] = $savedNational; unset($many, $item, $note); }
$smallZip = new ZipBuilder(8); $smallZip->add('á.txt', '12345678'); nationalReject(fn() => $smallZip->add('b.txt', '9'), 'ZIP writer stops before configured decompressed limit');
nationalReject(fn() => $smallZip->add('../escape.xml', 'x'), 'ZIP writer rejects path traversal');
nationalReject(fn() => $smallZip->add('á.txt', ''), 'ZIP writer rejects duplicate names');

// Native email readiness is real; only SMTP delivery is replaced by a spy.
final class MonthlyMailFixture {
    public function clear($attachments = false): void {} public function from(...$args): void {} public function to(...$args): void {}
    public function subject(...$args): void {} public function message(...$args): void {} public function attach(...$args): void {} public function send(): bool { throw new LogicException('Unexpected native SMTP send'); }
}
final class MonthlyLoaderFixture { public function library(string $name): void { if ($name !== 'email') { throw new LogicException('Unexpected library'); } } }
$monthlyCi = (object) ['load' => new MonthlyLoaderFixture(), 'email' => new MonthlyMailFixture()];
function &get_instance() { return $GLOBALS['monthlyCi']; }
$nationalOptions['smtp_email'] = 'remetente@example.invalid'; $nationalPermissions['accounting_send'] = true;
$model->configuration['accounting_recipients'] = [['id' => '1111111111111111', 'name' => 'Contabilidade A', 'email' => 'Conta@example.invalid'],
    ['id' => '2222222222222222', 'name' => 'Contabilidade B', 'email' => 'outro@example.invalid']];
$sent = []; $delivery = new MonthlyDelivery($model, static function ($emails, $zip, $filename, $month, $env) use (&$sent): void { $sent[] = [$emails, strlen($zip), $filename, $month, $env]; });
$deliveryFilters = array_replace($filters, ['format' => 'xml']); $deliveryReport = $package->report($deliveryFilters);
nationalReject(fn() => $delivery->send($deliveryFilters, $deliveryReport['fingerprint'], ['ffffffffffffffff']), 'delivery rejects recipient not registered in current company');
nationalCheck(!$sent && $delivery->history() === [], 'recipient validation does not create SMTP attempt');
$badFingerprint = str_repeat('0', 64); nationalReject(fn() => $delivery->send($deliveryFilters, $badFingerprint, ['1111111111111111']), 'changed package prevents SMTP attempt');
$nationalOptions['smtp_email'] = ''; nationalReject(fn() => $delivery->send($deliveryFilters, $deliveryReport['fingerprint'], ['1111111111111111']), 'missing native SMTP configuration fails before attempt');
nationalCheck(!$sent && $delivery->history() === [], 'generation/configuration failure leaves no sending record');
$nationalOptions['smtp_email'] = 'remetente@example.invalid';
$delivered = $delivery->send($deliveryFilters, $deliveryReport['fingerprint'], ['1111111111111111']);
nationalCheck($delivered['status'] === 'sent' && !$delivered['already_sent'] && count($sent) === 1 && $sent[0][0] === ['conta@example.invalid'], 'send resolves registered email and records one accepted SMTP attempt');
$again = $delivery->send($deliveryFilters, $deliveryReport['fingerprint'], ['1111111111111111']);
nationalCheck($again['already_sent'] && count($sent) === 1, 'confirmed delivery is idempotent for same package and email');
$model->configuration['accounting_recipients'][0]['id'] = '3333333333333333';
$again = $delivery->send($deliveryFilters, $deliveryReport['fingerprint'], ['3333333333333333']);
nationalCheck($again['already_sent'] && count($sent) === 1, 'changing recipient ID without changing email does not resend');
$history = $delivery->history();
nationalCheck(count($history) === 1 && $history[0]['recipient_label'] === 'Contabilidade A' && !isset($history[0]['recipients_enc']), 'history exposes safe current-company presentation without encrypted payload');
$deliveryTable = db_prefix() . 'nfse_nacional_accounting_deliveries';
nationalCheck(!str_contains(json_encode($database->tables[$deliveryTable]), 'conta@example.invalid')
    && preg_match('/^[a-f0-9]{64}$/D', $database->tables[$deliveryTable][0]['package_sha256']), 'delivery stores recipients encrypted and audits ZIP hash');
$uncertainSends = 0; $uncertain = new MonthlyDelivery($model, static function () use (&$uncertainSends): void { ++$uncertainSends; throw new RuntimeException('PRIVATE_SMTP_CANARY'); });
nationalReject(fn() => $uncertain->send($deliveryFilters, $deliveryReport['fingerprint'], ['2222222222222222']), 'SMTP exception yields inconclusive attempt');
nationalReject(fn() => $uncertain->send($deliveryFilters, $deliveryReport['fingerprint'], ['2222222222222222']), 'inconclusive attempt cannot resend automatically');
nationalCheck($uncertainSends === 1 && $uncertain->history()[0]['status'] === 'uncertain'
    && !str_contains(json_encode($database->tables[$deliveryTable]), 'PRIVATE_SMTP_CANARY'), 'uncertainty remains durable without sensitive SMTP text');
$model->configuration['accounting_recipients'][] = ['id' => '4444444444444444', 'name' => 'Contabilidade C', 'email' => 'terceiro@example.invalid'];
$beforeDatabaseFailure = count($sent); $historyCount = count($delivery->history());
$database->failWriteAfter = 0;
nationalReject(fn() => $delivery->send($deliveryFilters, $deliveryReport['fingerprint'], ['4444444444444444']), 'failed durable insert prevents SMTP');
nationalCheck(count($sent) === $beforeDatabaseFailure && count($delivery->history()) === $historyCount, 'failed sending persistence creates neither SMTP delivery nor false history');
$database->failWriteAfter = 1;
nationalReject(fn() => $delivery->send($deliveryFilters, $deliveryReport['fingerprint'], ['4444444444444444']), 'accepted SMTP with failed history update remains inconclusive');
nationalCheck(count($sent) === $beforeDatabaseFailure + 1 && $delivery->history()[0]['status'] === 'sending', 'durable sending survives failed confirmation write');
nationalReject(fn() => $delivery->send($deliveryFilters, $deliveryReport['fingerprint'], ['4444444444444444']), 'durable sending blocks a duplicate SMTP attempt');
nationalCheck(count($sent) === $beforeDatabaseFailure + 1, 'SMTP was not repeated after database confirmation failure');
$nationalPermissions['accounting_send'] = false;
nationalReject(fn() => $delivery->send($deliveryFilters, $deliveryReport['fingerprint'], ['3333333333333333']), 'dedicated accounting send permission is required even for prior package');
$nationalPermissions['accounting_send'] = true;
$nationalPrefix = 'national_b_';
nationalReject(fn() => $delivery->history(), 'history object cannot cross company context');
$otherDelivery = new MonthlyDelivery($otherModel); nationalCheck($otherDelivery->history() === [], 'other company cannot see delivery history');
$nationalPrefix = 'national_a_';

// Six individually valid, high-entropy XMLs exercise the real 20 MiB email cap.
// The stream-backed ZIP keeps this regression below the production 128 MiB PHP limit.
$savedLocal = $database->tables[$model->table('documents')]; $savedNational = $database->tables[$nationalTable];
$sendCountBeforeLimit = count($sent); $historyBeforeLimit = $delivery->history();
try {
    $database->tables[$nationalTable] = []; $database->tables[$model->table('documents')] = [];
    for ($i = 0; $i < 6; ++$i) {
        $largeItem = nationalNote(3000 + $i, $nationalTaxId, '11144477735');
        $largeItem['xml'] = str_replace('</infNFSe>', '<!--' . base64_encode(random_bytes(3700000)) . '--></infNFSe>', $largeItem['xml']);
        $database->tables[$model->table('documents')][] = monthlyLocalRow($largeItem, 3000 + $i);
        unset($largeItem);
    }
    $largeReport = $package->report($deliveryFilters); $sizeError = '';
    try { $delivery->send($deliveryFilters, $largeReport['fingerprint'], ['3333333333333333']); }
    catch (RuntimeException $error) { $sizeError = $error->getMessage(); }
    nationalCheck(str_contains($sizeError, '20 MB'), 'actual compressed package over 20 MiB is refused for SMTP');
    nationalCheck(count($sent) === $sendCountBeforeLimit && $delivery->history() === $historyBeforeLimit, 'oversized email creates no sending history and no SMTP call');
} finally {
    $database->tables[$model->table('documents')] = $savedLocal; $database->tables[$nationalTable] = $savedNational;
    unset($savedLocal, $savedNational, $largeReport);
}

echo 'OK: ' . $checks . " verificações de pacote mensal/ZIP e envio contábil. Sem e-mail ou API externos.\n";
