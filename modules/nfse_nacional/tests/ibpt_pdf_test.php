<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__ . '/');
require dirname(__DIR__) . '/autoload.php';

use NfseNacional\Certificate;
use NfseNacional\Danfe;
use NfseNacional\DpsBuilder;
use NfseNacional\DraftPreviewDocument;
use NfseNacional\DraftTax;
use NfseNacional\XmlSigner;

$checks = 0;
function ibptPdfCheck(bool $value, string $message): void
{
    if (!$value) { throw new RuntimeException('FALHOU: ' . $message); }
    ++$GLOBALS['checks'];
}
function ibptPdfRefuses(callable $action, string $message): void
{
    try { $action(); } catch (Throwable $expected) { ibptPdfCheck(true, $message); return; }
    ibptPdfCheck(false, $message);
}

/** Inspect embedded text without relying on host fonts or a system PDF program. */
function ibptPdfContains(string $pdf, string $text): bool
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

// Entirely synthetic and date-relative: no expired public table or real identity is bundled.
$today = new DateTimeImmutable('now', new DateTimeZone('America/Bahia'));
$snapshot = [
    'uf' => 'BA', 'nbs' => '115011000', 'description' => 'Serviço sintético de teste de software',
    'nacionalfederal' => '13.4500', 'estadual' => '0.0000', 'municipal' => '5.0000',
    'valid_from' => $today->modify('-1 day')->format('Y-m-d'), 'valid_to' => $today->modify('+1 day')->format('Y-m-d'),
    'chave' => 'TST071', 'versao' => 'TEST.071', 'fonte' => 'IBPT/empresometro.com.br',
    'fetched_at' => gmdate('Y-m-d\TH:i:s\Z'), 'source_hash' => hash('sha256', 'fixture-sintetica-ibpt-071'),
];
$draft = [
    'environment' => '2', 'competence' => $today->format('Y-m-d'), 'issued_at' => $today->format('Y-m-d\TH:i:sP'),
    'provider' => ['tax_id' => '11222333000181', 'name' => 'EMITENTE SINTÉTICO', 'state' => 'BA', 'municipality_code' => '2927408', 'simple_status' => '3', 'simple_apportionment' => '1', 'special_regime' => '0'],
    'recipient' => ['tax_id' => '52998224725', 'name' => 'TOMADOR SINTÉTICO', 'municipality_code' => '2927408', 'zip' => '40000000', 'street' => 'Rua de Exemplo', 'number' => '10', 'district' => 'Centro'],
    'service' => ['municipality_code' => '2927408', 'national_code' => '010101', 'nbs' => '115011000', 'description' => 'Serviço fictício & exclusivo para teste <offline>.'],
    'values' => ['service_amount' => '262.00', 'unconditional_discount' => '10.00', 'conditional_discount' => '12.00', 'iss_taxation' => '1', 'retention' => '2', 'iss_rate' => '3.00', 'simple_rate' => '3.00', 'total_tax_mode' => 'ibpt', 'total_federal' => '999.99', 'total_state' => '999.99', 'total_municipal' => '999.99'],
    'ibscbs' => ['enabled' => '0'], 'ibpt' => $snapshot,
];
$document = ['id' => 971, 'status' => 'draft', 'environment' => '2', 'attempts' => 0];
$builder = new DpsBuilder();
$estimate = DraftTax::ibpt($draft);
ibptPdfCheck($estimate['base'] === '252.00' && $estimate['total_federal'] === '33.89' && $estimate['total_state'] === '0.00' && $estimate['total_municipal'] === '12.60', 'IBPT calcula três esferas sobre serviços menos desconto incondicional');

$openssl = tempnam(sys_get_temp_dir(), 'ibpt_pdf_openssl_');
file_put_contents($openssl, "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=TESTE OFFLINE:11222333000181\n");
try {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $openssl]);
    $csr = openssl_csr_new(['commonName' => 'TESTE OFFLINE:11222333000181'], $key, ['config' => $openssl, 'digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $key, 1, ['config' => $openssl, 'digest_alg' => 'sha256']);
    $pfx = '';
    ibptPdfCheck(openssl_pkcs12_export($cert, $pfx, $key, 'offline-test'), 'Certificado efêmero para XSD e assinatura offline');
} finally { unlink($openssl); }
$certificate = Certificate::fromPkcs12($pfx, 'offline-test', '11222333000181');

foreach (['1', '2'] as $environment) {
    $candidate = $draft; $candidate['environment'] = $environment;
    $xml = $builder->build($candidate, '1', '971');
    $dom = XmlSigner::loadSafe($xml); $xp = new DOMXPath($dom); $xp->registerNamespace('n', DpsBuilder::NS);
    $base = '/n:DPS/n:infDPS/';
    ibptPdfCheck($xp->evaluate('string(' . $base . 'n:serv/n:infoCompl/n:xInfComp)') === $estimate['text'], 'Texto IBPT completo no grupo oficial de informações complementares, ambiente ' . $environment);
    ibptPdfCheck($xp->evaluate('string(' . $base . 'n:serv/n:cServ/n:xDescServ)') === $draft['service']['description'], 'Descrição da prestação não é alterada para acrescentar impostos, ambiente ' . $environment);
    foreach (['Fed' => '33.89', 'Est' => '0.00', 'Mun' => '12.60'] as $suffix => $value) {
        ibptPdfCheck($xp->evaluate('string(' . $base . 'n:valores/n:trib/n:totTrib/n:vTotTrib/n:vTotTrib' . $suffix . ')') === $value, 'Total IBPT ' . $suffix . ' recalculado sem confiar nos valores manuais, ambiente ' . $environment);
    }
    ibptPdfCheck((int) $xp->evaluate('count(' . $base . 'n:valores/n:trib/n:totTrib/*)') === 1 && (int) $xp->evaluate('count(//n:pTotTribSN)') === 0, 'IBPT seleciona apenas vTotTrib, sem duplicar pTotTribSN, ambiente ' . $environment);
    ibptPdfCheck((int) $xp->evaluate('count(//n:vISSQN | //n:vBC)') === 0, 'DPS não recebe apuração oficial fabricada, ambiente ' . $environment);
    ibptPdfCheck(str_contains($xml, 'Fonte: IBPT [TST071].') && str_contains($xml, 'Alíquota do Simples Nacional: 3,0000%.'), 'Fonte e alíquota SN constam sem substituir ISSQN, ambiente ' . $environment);
    $signed = XmlSigner::sign($xml, $certificate);
    ibptPdfCheck(XmlSigner::verify($signed), 'Assinatura íntegra com IBPT no ambiente ' . $environment);
    $errors = $builder->validateSchema($signed, $environment);
    ibptPdfCheck($errors === [], 'DPS IBPT atende XSD ambiente ' . $environment . ': ' . implode('; ', $errors));
}

// These known exclusions are independent of an agreement lookup. A rate may
// remain configured for an explicitly labelled simulation without entering DPS.
foreach ([
    'MEI' => ['2', '', '0', '1', '1', false],
    'ME/EPP sem retenção, ISS pelo SN' => ['3', '1', '0', '1', '1', false],
    'não incidência' => ['1', '', '0', '4', '1', false],
    'regime especial' => ['1', '', '1', '1', '1', false],
    'ME/EPP com retenção, ISS pelo SN' => ['3', '1', '0', '1', '2', true],
    'demais operações mantêm declaração informada' => ['1', '', '0', '1', '1', true],
] as $case => [$simple, $apportionment, $regime, $taxation, $retention, $declared]) {
    foreach (['1', '2'] as $environment) {
        $candidate = $draft; $candidate['environment'] = $environment;
        $candidate['provider']['simple_status'] = $simple;
        $candidate['provider']['simple_apportionment'] = $apportionment;
        $candidate['provider']['special_regime'] = $regime;
        $candidate['values']['iss_taxation'] = $taxation;
        $candidate['values']['retention'] = $retention;
        $xml = $builder->build($candidate, '1', '971');
        $dom = XmlSigner::loadSafe($xml); $xp = new DOMXPath($dom); $xp->registerNamespace('n', DpsBuilder::NS);
        ibptPdfCheck(((int) $xp->evaluate('count(//n:pAliq)') === 1) === $declared, 'pAliq respeita ' . $case . ' no ambiente ' . $environment);
        ibptPdfCheck($builder->validateSchema(XmlSigner::sign($xml, $certificate), $environment) === [], 'XSD preservado ao tratar pAliq: ' . $case . ', ambiente ' . $environment);
    }
}

$projection = new DraftPreviewDocument($draft, $document);
ibptPdfCheck($projection->text('preview/iss_base') === '252.00' && $projection->text('preview/iss_amount') === '7.56' && $projection->text('preview/iss_rate') === '3.0000', 'Prévia calcula ISSQN em campos exclusivamente locais');
ibptPdfCheck($projection->text('valores/vISSQN') === '' && $projection->text('valores/vBC') === '' && $projection->text('valores/vLiq') === '', 'Projeção local não preenche os caminhos da apuração oficial');
ibptPdfCheck($projection->dpsText('valores/trib/totTrib/vTotTrib/vTotTribMun') === '12.60' && $projection->text('preview/iss_amount') === '7.56', 'Tributo aproximado municipal permanece diferente do ISSQN calculado');
ibptPdfCheck($projection->dpsText('serv/infoCompl/xInfComp') === $estimate['text'] && $projection->dpsText('valores/trib/totTrib/pTotTribSN') === '', 'Prévia usa o mesmo texto e o ramo único IBPT da DPS');

foreach (['missing' => null, 'uf' => 'SP', 'nbs' => '115012000', 'expired' => $today->modify('-2 days')->format('Y-m-d'), 'key' => '', 'error' => 'Atualize a tabela.'] as $case => $value) {
    $candidate = $draft;
    if ($case === 'missing') { unset($candidate['ibpt']); }
    elseif ($case === 'uf') { $candidate['ibpt']['uf'] = $value; }
    elseif ($case === 'nbs') { $candidate['service']['nbs'] = $value; }
    elseif ($case === 'expired') { $candidate['ibpt']['valid_from'] = $today->modify('-3 days')->format('Y-m-d'); $candidate['ibpt']['valid_to'] = $value; }
    elseif ($case === 'key') { $candidate['ibpt']['chave'] = $value; }
    else { $candidate['ibpt_error'] = $value; }
    if ($case === 'expired') {
        $xml = $builder->build($candidate, '1', '971');
        ibptPdfCheck(str_contains($xml, 'Fonte: IBPT [TST071]'), 'DPS permite IBPT vencido com chave rastreável');
        $preview = new DraftPreviewDocument($candidate, $document);
        ibptPdfCheck($preview->dpsText('valores/trib/totTrib/vTotTrib/vTotTribFed') === '33.89' && $preview->text('preview/ibpt_error') === '', 'Prévia calcula normalmente com IBPT vencido');
        continue;
    }
    ibptPdfRefuses(static fn() => $builder->build($candidate, '1', '971'), 'DPS bloqueia IBPT inválido: ' . $case);
    $invalid = new DraftPreviewDocument($candidate, $document);
    ibptPdfCheck($invalid->dpsText('valores/trib/totTrib/vTotTrib/vTotTribFed') === '' && $invalid->dpsText('serv/infoCompl/xInfComp') === '' && $invalid->text('preview/ibpt_error') !== '', 'Prévia mantém aviso sem inventar tributos ou aceitar totais manuais: ' . $case);
}
foreach (['', 'não numérico'] as $badAmount) {
    $candidate = $draft; $candidate['values']['service_amount'] = $badAmount;
    $invalid = new DraftPreviewDocument($candidate, $document);
    ibptPdfCheck($invalid->text('preview/iss_amount') === '' && $invalid->text('preview/iss_base') === '', 'Valor inválido não gera base e ISS locais fictícios');
}
$candidate = $draft; unset($candidate['values']['service_amount']);
ibptPdfCheck((new DraftPreviewDocument($candidate, $document))->text('preview/iss_amount') === '', 'Serviço ausente permanece em branco na prévia de ISS');
$candidate = $draft; $candidate['values']['iss_taxation'] = '4';
ibptPdfCheck((new DraftPreviewDocument($candidate, $document))->text('preview/iss_amount') === '', 'Não incidência não gera cálculo local de ISS');
$candidate = $draft; $candidate['values']['iss_rate'] = '';
ibptPdfCheck((new DraftPreviewDocument($candidate, $document))->text('preview/iss_amount') === '', 'Alíquota ausente não é substituída pela alíquota municipal IBPT');

$pdfs = ['previa-ibpt' => Danfe::preview($draft, $document)];
foreach (['BC ISSQN (prévia)', 'ISSQN calculado (prévia)', 'R$ 7,56', 'R$ 33,89', 'R$ 12,60', 'Fonte: IBPT [TST071].', 'Alíquota do Simples Nacional: 3,0000%.', 'PRÉVIA SEM VALIDADE FISCAL'] as $text) {
    ibptPdfCheck(ibptPdfContains($pdfs['previa-ibpt'], $text), 'DANFSe prévio contém ' . $text);
}
$candidate = $draft; $candidate['ibpt_error'] = 'Tabela indisponível.';
$pdfs['previa-ibpt-indisponivel'] = Danfe::preview($candidate, $document);
ibptPdfCheck(ibptPdfContains($pdfs['previa-ibpt-indisponivel'], 'Tributos aproximados não calculados.') && !ibptPdfContains($pdfs['previa-ibpt-indisponivel'], 'Fonte: IBPT [TST071]'), 'PDF do rascunho inválido informa indisponibilidade sem fonte ou carga fictícia');

$dps = preg_replace('/<\?xml[^?]*\?>\s*/', '', $builder->build($draft, '1', '971'));
$accessKey = str_repeat('1', 50);
$authorized = '<?xml version="1.0" encoding="UTF-8"?><NFSe xmlns="' . DpsBuilder::NS . '" versao="1.01"><infNFSe Id="NFS' . $accessKey . '">'
    . '<xLocEmi>Salvador</xLocEmi><nNFSe>971</nNFSe><ambGer>2</ambGer><cStat>100</cStat><dhProc>' . $today->format('Y-m-d\TH:i:sP') . '</dhProc>'
    . '<emit><CNPJ>11222333000181</CNPJ><xNome>EMITENTE SINTÉTICO</xNome></emit>'
    . '<valores><vBC>247.00</vBC><pAliqAplic>4.00</pAliqAplic><vISSQN>9.88</vISSQN><vLiq>230.12</vLiq></valores>'
    . $dps . '</infNFSe></NFSe>';
$originalHash = hash('sha256', $authorized);
$pdfs['autorizada-ibpt-sintetica'] = Danfe::render($authorized);
ibptPdfCheck(hash('sha256', $authorized) === $originalHash, 'Renderização preserva os bytes do XML autorizado');
ibptPdfCheck(ibptPdfContains($pdfs['autorizada-ibpt-sintetica'], 'ISSQN Apurado') && ibptPdfContains($pdfs['autorizada-ibpt-sintetica'], 'R$ 9,88') && ibptPdfContains($pdfs['autorizada-ibpt-sintetica'], 'R$ 247,00'), 'DANFSe autorizado utiliza base e ISSQN do XML recebido');
ibptPdfCheck(!ibptPdfContains($pdfs['autorizada-ibpt-sintetica'], 'ISSQN calculado (prévia)') && !ibptPdfContains($pdfs['autorizada-ibpt-sintetica'], 'R$ 7,56'), 'DANFSe autorizado não substitui a apuração pelo cálculo local');
ibptPdfCheck(ibptPdfContains($pdfs['autorizada-ibpt-sintetica'], 'Fonte: IBPT [TST071].') && ibptPdfContains($pdfs['autorizada-ibpt-sintetica'], 'R$ 33,89'), 'DANFSe autorizado mantém informação IBPT que integra seu próprio XML');

$output = (string) ($argv[1] ?? '');
if ($output !== '' && !is_dir($output) && !mkdir($output, 0700, true)) { throw new RuntimeException('Falha ao criar diretório de testes do DANFSe.'); }
foreach ($pdfs as $name => $pdf) {
    ibptPdfCheck(str_starts_with($pdf, '%PDF-') && preg_match('~/Count\s+1\b~', $pdf) === 1, $name . ' mantém DANFSe A4 em uma página');
    ibptPdfCheck(preg_match('~/BaseFont\s*/[A-Za-z0-9+_-]*Arial[A-Za-z0-9+_-]*~i', $pdf) === 1 && preg_match('~/BaseFont\s*/[A-Za-z0-9+_-]*MicrosoftSansSerif[A-Za-z0-9+_-]*~i', $pdf) === 1 && !preg_match('/LiberationSans|FreeSans/i', $pdf), $name . ' mantém as fontes originais incorporadas no módulo');
    if ($output !== '') { file_put_contents($output . '/' . $name . '.pdf', $pdf); }
}
echo 'OK: ' . $checks . " verificações IBPT, DPS, prévia de ISSQN e DANFSe. Nenhuma consulta ou transmissão real.\n";
