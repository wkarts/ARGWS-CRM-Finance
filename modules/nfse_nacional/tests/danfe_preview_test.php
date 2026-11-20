<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__ . '/');
require dirname(__DIR__) . '/autoload.php';

$checks = 0;
function previewCheck(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException('FALHOU: ' . $message); }
    ++$GLOBALS['checks'];
}
function previewRejected(callable $action, string $message): void
{
    try { $action(); } catch (Throwable $expected) { previewCheck(true, $message); return; }
    previewCheck(false, $message);
}
function previewTextParts(string $pdf): array
{
    $streams = ''; $maps = [];
    preg_match_all('~stream\r?\n(.*?)\r?\nendstream~s', $pdf, $matches);
    foreach ($matches[1] as $raw) {
        $stream = @gzuncompress($raw) ?: $raw;
        $streams .= $stream;
        if (!str_contains($stream, 'begincmap')) { continue; }
        $map = [];
        preg_match_all('~beginbfchar(.*?)endbfchar~s', $stream, $blocks);
        foreach ($blocks[1] as $block) {
            preg_match_all('~<([0-9a-f]+)>\s*<([0-9a-f]+)>~i', $block, $pairs, PREG_SET_ORDER);
            foreach ($pairs as $pair) { $map[strtolower($pair[2])] = strtolower($pair[1]); }
        }
        if ($map !== []) { $maps[] = $map; }
    }
    return [$streams, $maps];
}
function previewContains(string $pdf, string $text): bool
{
    [$streams, $maps] = previewTextParts($pdf);
    $utf16 = mb_convert_encoding($text, 'UTF-16BE', 'UTF-8');
    if (str_contains($streams, $text) || str_contains($streams, $utf16) || stripos($streams, bin2hex($utf16)) !== false) { return true; }
    foreach ($maps as $map) {
        $encoded = '';
        foreach (str_split(bin2hex($utf16), 4) as $character) {
            if (!isset($map[$character])) { continue 2; }
            $encoded .= $map[$character];
        }
        $literal = strtr(hex2bin($encoded), ["\\" => "\\\\", '(' => '\\(', ')' => '\\)', "\r" => '\\r', "\n" => '\\n']);
        if (str_contains($streams, $literal) || stripos($streams, $encoded) !== false) { return true; }
    }
    return false;
}

$draft = [
    'environment' => '2', 'competence' => '2026-11-10',
    'provider' => ['tax_id' => '11222333000181', 'name' => 'EMITENTE SINTÉTICO - TESTE DE SOFTWARE', 'municipality_code' => '2927408', 'simple_status' => '1', 'special_regime' => '0', 'email' => 'emitente@example.invalid'],
    'recipient' => ['tax_id' => '52998224725', 'name' => 'TOMADOR SINTÉTICO', 'municipality_code' => '2927408', 'zip' => '40000000', 'street' => 'Rua de Exemplo', 'number' => '10', 'district' => 'Centro', 'email' => 'tomador@example.invalid'],
    'service' => ['municipality_code' => '2927408', 'national_code' => '010101', 'description' => 'Prestação fictícia usada exclusivamente em teste de software. Não representa serviço faturado ou transmitido.'],
    'values' => ['service_amount' => '1250.50', 'unconditional_discount' => '10.00', 'conditional_discount' => '0.00', 'iss_taxation' => '1', 'retention' => '1', 'iss_rate' => '2.00', 'total_tax_mode' => 'none'],
    'ibscbs' => ['enabled' => '1', 'cst' => '000', 'class_code' => '000001', 'purpose' => '0', 'operation_indicator' => '100301', 'destination_indicator' => '0', 'final_consumer' => '1'],
];
$local = ['id' => 949, 'status' => 'draft', 'attempts' => 0, 'environment' => '2'];
$projection = new NfseNacional\DraftPreviewDocument($draft, $local);
previewCheck($projection->accessKey() === '' && $projection->number() === '' && $projection->consultationUrl() === '', 'projeção não gera identificador fiscal nem QR de consulta');
previewCheck($projection->dpsText('nDPS') === '' && $projection->dpsText('serie') === '' && $projection->dpsText('dhEmi') === '', 'prévia não atribui número, série ou data de emissão da DPS');
previewCheck($projection->dpsText('toma/xNome') === 'TOMADOR SINTÉTICO' && $projection->dpsText('prest/xNome') === $draft['provider']['name'], 'nomes vêm do instantâneo salvo');
previewCheck($projection->text('valores/vISSQN') === '' && $projection->text('valores/vLiq') === '' && $projection->text('IBSCBS/totCIBS/gCBS/vCBS') === '', 'prévia não inventa apuração nem valor líquido fiscal');
previewCheck($projection->dpsText('IBSCBS/valores/trib/gIBSCBS/cClassTrib') === '000001', 'perfil IBS/CBS é preservado para conferência');
previewCheck($projection->dpsText('valores/vServPrest/vServ') === '1250.50', 'valor do serviço preservado');

$output = (string) ($argv[1] ?? '');
if ($output !== '' && !is_dir($output) && !mkdir($output, 0700, true)) { throw new RuntimeException('Falha ao criar diretório de prévias de teste.'); }
$pdfs = [];
foreach (['draft' => 'RASCUNHO', 'ready' => 'NÃO TRANSMITIDA', 'rejected' => 'REJEITADA', 'processing' => 'AGUARDANDO CONFIRMAÇÃO', 'uncertain' => 'AGUARDANDO CONFIRMAÇÃO'] as $status => $stamp) {
    $document = array_replace($local, ['status' => $status, 'attempts' => in_array($status, ['rejected', 'processing', 'uncertain'], true) ? 1 : 0]);
    $pdf = NfseNacional\Danfe::preview($draft, $document, ['protocol' => 'CANARY-FAKE-PROTOCOL', 'access_key' => str_repeat('9', 50), 'status' => 'authorized']);
    $pdfs[$status] = $pdf;
    previewCheck(strncmp($pdf, '%PDF-', 5) === 0 && preg_match('~/Count\s+1\b~', $pdf) === 1, $status . ' gera PDF A4 de uma página');
    previewCheck(previewContains($pdf, $stamp) && previewContains($pdf, 'PRÉVIA SEM VALIDADE FISCAL'), $status . ' tem carimbo e aviso fiscal');
    previewCheck(previewContains($pdf, 'TOMADOR SINTÉTICO') && previewContains($pdf, 'R$ 1.250,50'), $status . ' mostra tomador e valor para conferência');
    previewCheck(!previewContains($pdf, 'CANARY-FAKE-PROTOCOL') && !previewContains($pdf, str_repeat('9', 50)), $status . ' não aceita protocolo/chave do chamador como autorização');
    previewCheck(preg_match('~/BaseFont\s*/[A-Za-z0-9+_-]*Arial[A-Za-z0-9+_-]*~i', $pdf) === 1 && preg_match('~/BaseFont\s*/[A-Za-z0-9+_-]*MicrosoftSansSerif[A-Za-z0-9+_-]*~i', $pdf) === 1 && !preg_match('/LiberationSans|FreeSans/i', $pdf), $status . ' usa fontes originais incorporadas');
}
$retried = NfseNacional\Danfe::preview($draft, array_replace($local, ['attempts' => 1]));
$pdfs['corrigida-apos-tentativa'] = $retried;
previewCheck(previewContains($retried, 'NÃO AUTORIZADA') && previewContains($retried, 'Houve tentativa anterior.'), 'rascunho após tentativa não declara ausência de transmissão anterior');
$empty = NfseNacional\Danfe::preview([], $local);
previewCheck(previewContains($empty, 'RASCUNHO') && preg_match('~/Count\s+1\b~', $empty) === 1, 'rascunho incompleto abre sem certificado ou validação de emissão');
$invalid = $draft;
$invalid['values']['service_amount'] = 'não numérico';
$invalid['provider']['name'] = ['estrutura inesperada'];
previewCheck(strncmp(NfseNacional\Danfe::preview($invalid, $local), '%PDF-', 5) === 0, 'campos locais inválidos não impedem prévia e não viram valores fictícios');
previewRejected(static fn() => NfseNacional\Danfe::preview($draft, array_replace($local, ['status' => 'authorized'])), 'nota autorizada nunca é reconstruída a partir do rascunho');
previewRejected(static fn() => NfseNacional\Danfe::preview($draft, array_replace($local, ['status' => 'cancelled'])), 'cancelamento nunca é forjado em uma prévia local');

$draft['issued_at'] = '2026-11-10T11:00:00-03:00';
$dps = (new NfseNacional\DpsBuilder())->build($draft, '1', '949');
$dps = preg_replace('/<\?xml[^?]*\?>\s*/', '', $dps);
$key = str_repeat('1', 50);
$xml = '<?xml version="1.0" encoding="UTF-8"?><NFSe xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.01"><infNFSe Id="NFS' . $key . '">'
    . '<xLocEmi>Salvador</xLocEmi><xLocPrestacao>Salvador</xLocPrestacao><nNFSe>949</nNFSe><ambGer>2</ambGer><cStat>100</cStat><dhProc>2026-11-10T11:01:00-03:00</dhProc>'
    . '<emit><CNPJ>11222333000181</CNPJ><xNome>EMITENTE SINTÉTICO DO XML</xNome></emit><xOutInf>XML SINTÉTICO PARA TESTE DE SOFTWARE. NENHUMA TRANSMISSÃO REAL.</xOutInf>'
    . $dps . '</infNFSe></NFSe>';
$pdfs['authorized'] = NfseNacional\Danfe::render($xml, ['status' => 'authorized', 'checked_at' => '2026-11-10 11:02:00']);
previewCheck(previewContains($pdfs['authorized'], $key) && previewContains($pdfs['authorized'], 'TRANSMITIDA E AUTORIZADA'), 'PDF autorizado conserva chave do XML e confirmação');
previewCheck(previewContains($pdfs['authorized'], 'não fornecido; identificação pela chave'), 'protocolo ausente não é substituído pelo DPS, número da nota ou evento');
previewCheck(previewContains($pdfs['authorized'], 'NFS-e SEM VALIDADE JURÍDICA'), 'nota de homologação mantém carimbo de ausência de validade jurídica');
$pdfs['cancelled'] = NfseNacional\Danfe::render($xml, ['status' => 'cancelled', 'is_cancelled' => true, 'cancellation_event_id' => 'EVT-SINTETICO-SEM-VALIDADE', 'cancelled_at' => '2026-11-10T12:00:00-03:00']);
previewCheck(previewContains($pdfs['cancelled'], 'CANCELADA') && previewContains($pdfs['cancelled'], 'EVT-SINTETICO-SEM-VALIDADE') && previewContains($pdfs['cancelled'], $key), 'cancelada preserva nota original e exibe evento separado');
previewCheck(previewContains($pdfs['cancelled'], 'CANCELADA - SEM VALIDADE JURÍDICA'), 'cancelada de homologação preserva aviso de ausência de validade no cabeçalho');
previewRejected(static fn() => NfseNacional\Danfe::render($xml, ['status' => 'cancelled']), 'carimbo cancelada exige confirmação fiscal');
previewRejected(static fn() => NfseNacional\Danfe::render($xml, ['status' => 'draft']), 'XML autorizado não aceita status local inconsistente');
foreach ($pdfs as $name => $pdf) { if ($output !== '') { file_put_contents($output . '/danfse-' . $name . '.pdf', $pdf); } }
echo 'OK: ' . $checks . " verificações de prévia, carimbos, identificação fiscal e fontes incorporadas.\n";
