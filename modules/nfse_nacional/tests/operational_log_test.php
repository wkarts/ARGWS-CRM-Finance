<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
require dirname(__DIR__) . '/autoload.php';

use NfseNacional\OperationalLog;

// Child writers use the same store to exercise the real filesystem lock.
if (($argv[1] ?? '') === '--worker') {
    $writer = new OperationalLog($argv[2], $argv[3], 7, static fn(): int => 1794312000);
    for ($i = 0; $i < 20; ++$i) {
        if ($writer->record('info', 'concurrency', 'Registro concorrente', ['attempt' => $i]) === null) { exit(2); }
    }
    exit(0);
}

$checks = 0;
$directory = sys_get_temp_dir() . '/nfse-operational-' . bin2hex(random_bytes(8));
$now = (new DateTimeImmutable('2026-11-10T12:00:00Z'))->getTimestamp();
$timezone = date_default_timezone_get();
$previousErrorLog = ini_get('error_log');
mkdir($directory, 0700);
ini_set('error_log', $directory . '/test-errors.log');
date_default_timezone_set('UTC');
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$logTestPrefix = 'company_native_'; $logTestStaff = 9; $logTestAuthenticated = true; $logTestLogPath = '';
$logTestCi = (object) ['db' => (object) ['database' => 'offline', 'hostname' => 'localhost'], 'config' => new class {
    public function item(string $key) { return $key === 'log_path' ? $GLOBALS['logTestLogPath'] : null; }
}];
define('APPPATH', $directory . '/application/');
function &get_instance(): object { return $GLOBALS['logTestCi']; }
function db_prefix(): string { return $GLOBALS['logTestPrefix']; }
function get_staff_user_id(): int { return $GLOBALS['logTestStaff']; }
function is_staff_logged_in(): bool { return $GLOBALS['logTestAuthenticated']; }
require dirname(__DIR__) . '/helpers/nfse_nacional_helper.php';

function logCheck(bool $ok, string $message): void
{
    if (!$ok) { throw new RuntimeException('FALHOU: ' . $message); }
    ++$GLOBALS['checks'];
}

function logStore(string $company, array $limits = []): OperationalLog
{
    return new OperationalLog($GLOBALS['directory'], hash('sha256', $company), 17, static fn(): int => $GLOBALS['now'], $limits);
}

function logPath(string $company): string
{
    return $GLOBALS['directory'] . '/nfse_nacional/' . hash('sha256', $company);
}

function logRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) { return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) { logRemoveTree($entry->getPathname()); }
    rmdir($path);
}

try {
    $store = logStore('company-a');
    logCheck($store->report()['storage'] === 'empty' && !file_exists(logPath('company-a')), 'consulta inicial não cria diretórios nem arquivos');
    logCheck($store->diagnostics()['writable'] && $store->diagnostics()['max_total_bytes'] === 20971520, 'capacidade limitada e diagnóstico da escrita');

    $correlation = str_repeat('a', 32);
    $context = ['environment' => '2', 'correlation_id' => $correlation, 'document_id' => 53, 'invoice_id' => 51,
        'http_status' => 404, 'duration_ms' => 21.541, 'method' => 'get', 'service' => 'municipality_parameters',
        'endpoint' => 'https://usuario:segredo@example.test/parametros_municipais/3550308/convenio?token=segredo#private',
        'municipality_code' => '3550308', 'operation_status' => 'failed', 'exception_class' => 'NfseNacional\\ApiException',
        'request_bytes' => 0, 'response_bytes' => 239, 'response_type' => 'application/json',
        'request_sha256' => hash('sha256', ''), 'response_sha256' => hash('sha256', 'synthetic-response'),
        'error_codes' => ['E0001', 'E9999'], 'errors' => [['code' => 'E0001', 'message' => 'Município não localizado.']],
        'request' => ['method' => 'GET', 'municipality_code' => '3550308', 'headers' => ['Authorization' => 'Bearer highlysecret']],
        'response' => ['http_status' => 404, 'errors' => [['code' => 'E0001', 'message' => 'Convênio não localizado.']]],
        'password' => 'senha-nao-armazenar', 'certificate' => 'certificado-nao-armazenar', 'body' => '<NFSe><Cpf>11144477735</Cpf></NFSe>',
        'payload' => base64_encode(str_repeat('sensitive', 100)), 'tax_id' => '11222333000181', 'customer_name' => 'Pessoa Exemplo'];
    $id = $store->record('warning', 'municipality.agreement', 'Consulta do convênio não concluída (HTTP 404).', $context);
    logCheck(is_string($id) && preg_match('/\A[a-f0-9]{32}\z/D', $id) === 1, 'cada registro recebe identificação aleatória');
    $row = $store->find($id);
    logCheck($row['staff_id'] === 17 && $row['environment'] === '2' && $row['document_id'] === 53 && $row['invoice_id'] === 51, 'contexto operacional preserva usuário e vínculos internos');
    logCheck($row['correlation_id'] === $correlation && $row['context']['http_status'] === 404 && $row['context']['duration_ms'] === 21.54, 'correlação e diagnóstico HTTP preservados');
    logCheck($row['context']['endpoint'] === 'https://example.test/parametros_municipais/3550308/convenio', 'endpoint exclui usuário, senha, query string e fragmento');
    logCheck($row['context']['request_sha256'] === $context['request_sha256'] && $row['context']['response_sha256'] === $context['response_sha256'], 'hashes dos conteúdos substituem payloads completos');
    logCheck($row['context']['service'] === 'municipality_parameters' && $row['context']['response_type'] === 'application/json'
        && $row['context']['exception_class'] === 'NfseNacional\\ApiException', 'nomes seguros de serviço, tipo da resposta e classe de exceção são preservados');
    logCheck($row['context']['errors'][0]['code'] === 'E0001' && $row['context']['request']['method'] === 'GET', 'detalhe mantém somente diagnóstico útil de requisição e resposta');
    $stored = json_encode($row, JSON_UNESCAPED_UNICODE);
    foreach (['highlysecret', 'senha-nao-armazenar', 'certificado-nao-armazenar', '11144477735', '11222333000181', 'Pessoa Exemplo', '<NFSe>', 'headers', 'payload'] as $secret) {
        logCheck(!str_contains($stored, $secret), 'whitelist remove dado não autorizado: ' . $secret);
    }
    $files = glob(logPath('company-a') . '/events-*.jsonl.php');
    logCheck(count($files) === 1 && str_starts_with(file_get_contents($files[0]), "<?php exit; ?>\n"), 'arquivo de dados bloqueia execução web antes do JSON');
    logCheck((fileperms($files[0]) & 0777) === 0600 && (fileperms(logPath('company-a')) & 0777) === 0700, 'arquivos e diretórios têm permissão restrita');
    logCheck(file_exists(logPath('company-a') . '/.htaccess') && file_exists(logPath('company-a') . '/index.html'), 'diretório inclui proteção contra acesso direto e listagem');

    $other = logStore('company-b');
    $otherId = $other->record('info', 'municipality.agreement', 'Operação de outra empresa.', ['environment' => '1']);
    logCheck($other->find($id) === null && $store->find($otherId) === null, 'identificador de evento não permite leitura entre empresas');
    logCheck($other->report()['total'] === 1 && $store->report()['total'] === 1, 'busca e contagem segregadas por empresa');

    $dps = 'DPS3550308211222333000181' . str_repeat('0', 20);
    $key = str_repeat('1', 50);
    $privacy = OperationalLog::sanitize(['errors' => [['code' => 'E0002', 'message' => 'CPF 111.444.777-35; CNPJ 11.222.333/0001-81; 11222333000181; AB.CDE.FGH/IJKL-12; email@exemplo.test; (11) 99999-0000; senha="segredo123"; Bearer abcsecret; ' . $dps . '; ' . $key]],
        'endpoint' => 'https://example.test/dps/' . $dps . '?secret=abc', 'environment' => new stdClass(),
        'request' => ['body' => 'secret', 'password' => 'secret', 'response' => ['request' => ['method' => 'GET']]],
        'unknown' => ['certificate' => 'secret']]);
    $privacyText = json_encode($privacy, JSON_UNESCAPED_UNICODE);
    foreach (['111.444.777-35', '11.222.333/0001-81', '11222333000181', 'AB.CDE.FGH/IJKL-12', 'email@exemplo.test', '99999-0000', 'segredo123', 'abcsecret', $dps, $key] as $secret) {
        logCheck(!str_contains($privacyText, $secret), 'mensagens e endpoints mascaram identificadores e credenciais: ' . substr($secret, 0, 18));
    }
    logCheck(!isset($privacy['environment'], $privacy['unknown']) && $privacy['request']['response']['request'] === [], 'tipos inválidos e profundidade excessiva são descartados');
    $xml = OperationalLog::sanitize(['errors' => [['message' => '<DPS><infDPS>11144477735</infDPS></DPS>']]]);
    logCheck($xml['errors'][0]['message'] === '[XML omitido]', 'XML nunca entra nos detalhes pelo campo de erro');
    $pem = OperationalLog::sanitize(['errors' => [['message' => "-----BEGIN PRIVATE KEY-----\nSECRETKEY\n-----END PRIVATE KEY-----"]]]);
    logCheck(!str_contains(json_encode($pem), 'SECRETKEY'), 'material criptográfico é removido das mensagens');
    $maximal = OperationalLog::sanitize(['errors' => array_fill(0, 50, ['code' => 'E0001', 'message' => str_repeat('x ', 1000)]), 'field_names' => array_fill(0, 60, 'municipality_code')]);
    logCheck(count($maximal['errors']) === 10 && mb_strlen($maximal['errors'][0]['message']) === 500 && count($maximal['field_names']) === 30, 'listas e textos de diagnóstico possuem limites explícitos');
    $badType = OperationalLog::sanitize(['http_status' => [], 'duration_ms' => INF, 'municipality_code' => [], 'invoice_id' => [], 'environment' => [], 'errors' => [new stdClass()], 'endpoint' => []]);
    logCheck($badType === ['errors' => []], 'dados malformados não emitem avisos nem provocam exceção');

    $events = [];
    for ($i = 1; $i <= 6; ++$i) {
        ++$now;
        $events[] = $store->record($i % 2 ? 'info' : 'error', 'lookup.cnpj', 'Consulta cadastral ' . $i,
            ['http_status' => $i % 2 ? 200 : 503, 'environment' => '2', 'correlation_id' => str_repeat('b', 32)]);
    }
    $page = $store->report([], 2, 2);
    logCheck($page['total'] === 7 && $page['pages'] === 4 && $page['has_more'] && array_column($page['rows'], 'id') === [$events[3], $events[2]], 'paginação mantém ordenação dos eventos mais recentes');
    logCheck($store->report(['level' => 'error', 'http_status' => '503', 'operation' => 'lookup.cnpj', 'environment' => '2'])['total'] === 3, 'combinação de filtros restringe a busca');
    logCheck($store->report(['search' => 'CONVÊNIO'])['total'] === 1, 'pesquisa ignora caixa e preserva acentos');
    logCheck($store->report(['correlation_id' => str_repeat('b', 8)])['total'] === 6, 'correlação parcial agrupa as etapas de uma mesma operação');
    logCheck($store->report(['environment' => '1'])['total'] === 0 && $store->find('../lock.php') === null, 'filtros sem resultados e ID de arquivo inválido não vazam registros');
    logCheck($store->report([], 0, 200)['per_page'] === 100 && $store->report([], 0, 200)['page'] === 1, 'limites de paginação são aplicados no servidor');

    date_default_timezone_set('America/Bahia');
    $dates = logStore('company-dates');
    foreach (['2026-11-09T23:59:59Z', '2026-11-10T02:59:59Z', '2026-11-10T03:00:00Z', '2026-11-11T02:59:59Z', '2026-11-11T03:00:00Z'] as $stamp) {
        $now = (new DateTimeImmutable($stamp))->getTimestamp();
        $dates->record('info', 'dates', $stamp);
    }
    logCheck($dates->report(['date_from' => '2026-11-10', 'date_to' => '2026-11-10'])['total'] === 2, 'filtros usam o mesmo dia civil e fuso da interface');
    logCheck($dates->report(['date_to' => '2026-11-09'])['total'] === 2, 'data final inclui o dia local inteiro sem invadir o próximo');
    logCheck($dates->report(['date_from' => '2026-02-31'])['total'] === 5, 'data inválida não vira silenciosamente outra data');
    date_default_timezone_set('UTC');

    $export = $store->export(['http_status' => 404]);
    logCheck($export['count'] === 1 && !$export['truncated'] && !str_contains($export['content'], '<?php'), 'exportação retorna somente JSON Lines filtrado');
    logCheck(json_decode(trim($export['content']), true)['id'] === $id, 'exportação é parseável e preserva o evento selecionado');
    $smallExport = logStore('company-a', ['max_export_records' => 2])->export();
    logCheck($smallExport['count'] === 2 && $smallExport['truncated'], 'exportação informa quando atinge o limite de registros');

    $now = (new DateTimeImmutable('2026-11-10T12:00:00Z'))->getTimestamp();
    $rotation = logStore('company-rotation', ['max_file_bytes' => 32768, 'max_files' => 2, 'retention_days' => 2]);
    $first = null;
    for ($i = 0; $i < 100; ++$i) {
        $event = $rotation->record('info', 'rotation', str_repeat('Detalhe operacional. ', 58), ['attempt' => $i]);
        if ($i === 0) { $first = $event; }
        logCheck($event !== null, 'rotação mantém escrita funcional no evento ' . $i);
    }
    $rotationFiles = glob(logPath('company-rotation') . '/events-*.jsonl.php');
    logCheck(count($rotationFiles) === 2 && max(array_map('filesize', $rotationFiles)) <= 32768, 'rotação limita quantidade e tamanho de segmentos');
    logCheck($rotation->find($first) === null && $rotation->report()['total'] > 0, 'limite retém os registros recentes e descarta os antigos');
    $now += 3 * 86400;
    logCheck($rotation->report()['total'] === 0, 'leitura também respeita retenção mesmo antes de nova escrita');
    $rotation->record('info', 'rotation', 'Novo período.');
    logCheck(count(glob(logPath('company-rotation') . '/events-*.jsonl.php')) === 1, 'nova escrita remove segmentos expirados');

    $corrupt = logStore('company-corrupt');
    $validId = $corrupt->record('info', 'integrity', 'Registro válido.');
    $corruptFile = glob(logPath('company-corrupt') . '/events-*.jsonl.php')[0];
    file_put_contents($corruptFile, "{invalid json}\n" . json_encode(['id' => ['invalid'], 'created_at' => '2026-11-10T12:00:00Z']) . "\n", FILE_APPEND);
    $corruptReport = $corrupt->report();
    logCheck($corruptReport['total'] === 1 && $corruptReport['truncated'] && $corrupt->find($validId) !== null, 'linhas corrompidas não apagam os registros válidos nem passam despercebidas');
    $tamperedId = str_repeat('f', 32);
    $tampered = ['id' => $tamperedId, 'created_at' => gmdate('Y-m-d\TH:i:s\Z', $now), 'level' => 'error', 'operation' => 'integrity',
        'message' => 'CPF 11144477735; token="TAMPERED_SECRET";', 'body' => '<NFSe>RAW_XML</NFSe>', 'staff_id' => ['wrong'],
        'context' => ['body' => '<NFSe>RAW_XML</NFSe>', 'headers' => ['Authorization' => 'TAMPERED_HEADER'],
            'request' => ['payload' => 'TAMPERED_REQUEST', 'method' => 'POST'],
            'response' => ['http_status' => 400, 'password' => 'TAMPERED_PASSWORD', 'errors' => [['message' => 'email@exemplo.test']]]]];
    file_put_contents($corruptFile, json_encode($tampered) . "\n", FILE_APPEND);
    foreach ([$corrupt->find($tamperedId), $corrupt->report()['rows'], $corrupt->export()['content']] as $read) {
        $safe = is_string($read) ? $read : json_encode($read);
        logCheck(!str_contains($safe, 'TAMPERED_') && !str_contains($safe, 'RAW_XML') && !str_contains($safe, '11144477735')
            && !str_contains($safe, 'email@exemplo.test'), 'detalhe, listagem e exportação reaplicam sanitização em arquivo adulterado');
    }

    $symlink = logStore('company-symlink');
    symlink(logPath('company-a'), logPath('company-symlink'));
    logCheck($symlink->record('error', 'security', 'Não deve entrar em outra empresa.') === null, 'escrita recusa diretório de empresa redirecionado por symlink');
    logCheck($symlink->report()['storage'] === 'unavailable' && !$symlink->diagnostics()['available'], 'leitura e diagnóstico recusam o mesmo symlink');
    unlink(logPath('company-symlink'));
    $linkedFile = logStore('company-linked-file');
    mkdir(logPath('company-linked-file'), 0700);
    symlink($files[0], logPath('company-linked-file') . '/lock.php');
    logCheck($linkedFile->record('info', 'security', 'Não gravar.') === null && $linkedFile->report()['storage'] === 'unavailable', 'arquivo de lock redirecionado não permite acesso');
    unlink(logPath('company-linked-file') . '/lock.php');
    $eventLinkStore = logStore('company-event-link');
    $eventLinkStore->record('info', 'security', 'Somente esta empresa.');
    symlink($files[0], logPath('company-event-link') . '/events-20261110-999999.jsonl.php');
    logCheck($eventLinkStore->find($id) === null && $eventLinkStore->report()['total'] === 1 && $eventLinkStore->export()['count'] === 1, 'segmento symlink de outra empresa é ignorado em todos os caminhos de leitura');

    $blockedBase = $directory . '/not-a-directory';
    file_put_contents($blockedBase, 'sentinel');
    $blocked = new OperationalLog($blockedBase, hash('sha256', 'blocked'));
    logCheck($blocked->record('error', 'storage', 'Registro impossível.') === null, 'falha de armazenamento não interrompe operação fiscal');
    logCheck(!$blocked->diagnostics()['available'] && $blocked->report()['storage'] === 'unavailable', 'falha de armazenamento aparece explicitamente no diagnóstico e na busca');
    foreach ([['relative', hash('sha256', 'x')], ['php://filter', hash('sha256', 'x')], [$directory, '../other'], [$directory, str_repeat('g', 64)]] as [$base, $scope]) {
        try { new OperationalLog($base, $scope); throw new RuntimeException('Contexto inválido aceito'); }
        catch (InvalidArgumentException $expected) { logCheck(true, 'caminho e escopo exigem origem confiável e formato válido'); }
    }

    $_POST = ['scope' => hash('sha256', 'company-b'), 'company_id' => 'foreign', 'staff_id' => 999];
    $current = OperationalLog::current();
    $currentId = $current->record('info', 'context', 'Contexto nativo autenticado.');
    logCheck(is_file(APPPATH . 'logs/nfse_nacional/' . nfse_nacional_scope() . '/lock.php')
        && $current->find($currentId)['staff_id'] === 9, 'factory usa log_path padrão e contexto autenticado, ignorando dados do formulário');
    $logTestPrefix = 'other_company_';
    logCheck(OperationalLog::current()->find($currentId) === null, 'troca do contexto confiável separa o armazenamento da factory');
    $logTestLogPath = $directory . '/custom-logs/';
    $customCurrentId = OperationalLog::current()->record('info', 'context', 'Caminho configurado no CRM.');
    logCheck($customCurrentId !== null && is_file($logTestLogPath . 'nfse_nacional/' . nfse_nacional_scope() . '/lock.php'), 'factory respeita log_path confiável da configuração do CRM');
    $logTestAuthenticated = false;
    try { OperationalLog::current(); throw new LogicException('Factory aceitou usuário não autenticado'); }
    catch (RuntimeException $expected) { logCheck(true, 'factory recusa contexto sem sessão autenticada'); }
    $logTestAuthenticated = true; $_POST = [];

    $locked = logStore('company-lock');
    $locked->record('info', 'locking', 'Inicial.');
    $heldLock = fopen(logPath('company-lock') . '/lock.php', 'r+b');
    flock($heldLock, LOCK_EX);
    $start = microtime(true);
    logCheck($locked->record('info', 'locking', 'Ocupado.') === null && microtime(true) - $start < 1, 'lock ocupado tem espera limitada e falha aberta');
    logCheck($locked->report()['storage'] === 'unavailable', 'leitura não finge ausência de registros quando lock está ocupado');
    flock($heldLock, LOCK_UN); fclose($heldLock);
    logCheck($locked->report()['total'] === 1, 'liberação do lock restaura o acesso sem corrupção');

    $concurrent = logStore('company-concurrent');
    $children = [];
    for ($i = 0; $i < 4; ++$i) {
        $command = [PHP_BINARY, '-n', '-d', 'extension_dir=' . ini_get('extension_dir'), '-d', 'extension=mbstring', '-d', 'extension=ctype',
            __FILE__, '--worker', $directory, hash('sha256', 'company-concurrent')];
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Não foi possível iniciar escritor concorrente de teste.'); }
        $children[] = [$process, $pipes];
    }
    foreach ($children as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]); $errorOutput = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        logCheck(proc_close($process) === 0, 'processo concorrente conclui gravações: ' . $output . $errorOutput);
    }
    logCheck($concurrent->report()['total'] === 80 && !$concurrent->report()['truncated'], 'gravações concorrentes preservam todos os eventos sem JSON parcial');
    logCheck(count(array_unique(array_column($concurrent->report([], 1, 100)['rows'], 'id'))) === 80, 'eventos concorrentes recebem IDs distintos');

    echo 'OK: ' . $checks . " verificações de log operacional.\n";
} finally {
    restore_error_handler();
    date_default_timezone_set($timezone);
    ini_set('error_log', $previousErrorLog);
    logRemoveTree($directory);
}
