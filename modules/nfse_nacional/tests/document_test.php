<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Offline parser/PDF/email tests. Optional argument: /tmp/output-dir.
// The PDF engine and fonts must come from the module itself, without a Perfex install.
define('BASEPATH', __DIR__ . '/');
require dirname(__DIR__) . '/autoload.php';
$documentOutput = (string) ($argv[1] ?? (getenv('PDF_TEST_OUTPUT') ?: ''));

$documentChecks = 0;
function docCheck(bool $value, string $label): void
{
    if (!$value) { throw new RuntimeException('FALHOU: ' . $label); }
    ++$GLOBALS['documentChecks'];
}
function docReject(callable $action, string $label): void
{
    $failed = false;
    try { $action(); } catch (Throwable $expected) { $failed = true; }
    docCheck($failed, $label);
}
function docPdfContains(string $text, string $streams, array $unicodeMaps): bool
{
    $utf16 = mb_convert_encoding($text, 'UTF-16BE', 'UTF-8');
    if (str_contains($streams, $text) || str_contains($streams, $utf16) || stripos($streams, bin2hex($utf16)) !== false) { return true; }
    // Subset fonts may encode glyph IDs rather than Unicode values in PDF text operators.
    // Resolve the fixture text through the PDF's own ToUnicode maps; no font-specific IDs are assumed.
    foreach ($unicodeMaps as $map) {
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
function docPdfStreams(string $pdf): array
{
    $streams = ''; $unicodeMaps = [];
    preg_match_all('~stream\r?\n(.*?)\r?\nendstream~s', $pdf, $matches);
    foreach ($matches[1] as $stream) {
        $stream = @gzuncompress($stream) ?: $stream;
        $streams .= $stream;
        if (!str_contains($stream, 'begincmap')) { continue; }
        $map = [];
        preg_match_all('~beginbfchar(.*?)endbfchar~s', $stream, $blocks);
        foreach ($blocks[1] as $block) {
            preg_match_all('~<([0-9a-f]+)>\s*<([0-9a-f]+)>~i', $block, $pairs, PREG_SET_ORDER);
            foreach ($pairs as $pair) { $map[strtolower($pair[2])] = strtolower($pair[1]); }
        }
        if ($map !== []) { $unicodeMaps[] = $map; }
    }
    return [$streams, $unicodeMaps];
}

$key = '2927408' . '2' . '2' . '12345678000195' . str_pad('123', 13, '0', STR_PAD_LEFT) . '2611' . '123456789' . '1';
$dpsId = 'DPS29274082' . '12345678000195' . '00001' . str_pad('78', 15, '0', STR_PAD_LEFT);
$xml = '<?xml version="1.0" encoding="UTF-8"?>' . <<<XML
<NFSe xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.01">
 <infNFSe Id="NFS{$key}">
  <xLocEmi>Salvador</xLocEmi><xLocPrestacao>Salvador</xLocPrestacao><nNFSe>123</nNFSe>
  <cLocIncid>2927408</cLocIncid><xLocIncid>Salvador</xLocIncid><xTribNac>Serviços de demonstração</xTribNac>
  <ambGer>2</ambGer><cStat>100</cStat><dhProc>2026-11-10T11:00:00-03:00</dhProc>
  <emit><CNPJ>12345678000195</CNPJ><IM>123456</IM><xNome>PRESTADOR FICTICIO DO XML</xNome><enderNac><xLgr>Rua de Exemplo</xLgr><nro>10</nro><xBairro>Centro</xBairro><cMun>2927408</cMun><UF>BA</UF><CEP>40000000</CEP></enderNac><email>emitente@example.invalid</email></emit>
  <valores><vBC>1000.00</vBC><pAliqAplic>2.00</pAliqAplic><vISSQN>20.00</vISSQN><vTotalRet>0.00</vTotalRet><vLiq>1000.00</vLiq></valores>
  <xOutInf>DOCUMENTO SINTETICO PARA TESTE DE SOFTWARE</xOutInf>
  <DPS versao="1.01"><infDPS Id="{$dpsId}">
   <tpAmb>2</tpAmb><dhEmi>2026-11-10T10:59:00-03:00</dhEmi><serie>1</serie><nDPS>78</nDPS><dCompet>2026-11-10</dCompet><tpEmit>1</tpEmit><cLocEmi>2927408</cLocEmi>
   <prest><CNPJ>12345678000195</CNPJ><regTrib><opSimpNac>1</opSimpNac><regEspTrib>0</regEspTrib></regTrib></prest>
   <toma><CPF>11144477735</CPF><xNome>TOMADOR FICTICIO DO XML</xNome><end><endNac><cMun>2927408</cMun><CEP>40000000</CEP></endNac><xLgr>Rua do Tomador</xLgr><nro>20</nro><xBairro>Centro</xBairro></end><email>tomador@example.invalid</email></toma>
   <serv><locPrest><cLocPrestacao>2927408</cLocPrestacao></locPrest><cServ><cTribNac>010101</cTribNac><xDescServ>Prestação fictícia usada exclusivamente para verificar o DANFSe. Nenhum serviço foi faturado ou transmitido neste teste.</xDescServ></cServ><infoCompl><xInfComp>Referência fiscal preservada no XML autorizado.</xInfComp></infoCompl></serv>
   <valores><vServPrest><vServ>1000.00</vServ></vServPrest><trib><tribMun><tribISSQN>1</tribISSQN><tpRetISSQN>1</tpRetISSQN></tribMun><totTrib><vTotTrib><vTotTribFed>90.00</vTotTribFed><vTotTribEst>0.00</vTotTribEst><vTotTribMun>20.00</vTotTribMun></vTotTrib></totTrib></trib></valores>
  </infDPS></DPS>
 </infNFSe>
</NFSe>
XML;

$note = new NfseNacional\NfseDocument($xml);
docCheck($note->accessKey() === $key && strlen($key) === 50, 'chave extraída sem prefixo');
docCheck($note->dpsId() === $dpsId && $note->number() === '123', 'DPS e número preservados');
docCheck($note->environment() === '2' && str_contains($note->consultationUrl(), 'producaorestrita'), 'ambiente restrito não aponta para consulta de produção');
docCheck($note->dpsText('toma/xNome') === 'TOMADOR FICTICIO DO XML', 'tomador vem somente do XML');
foreach (['100', '102', '103', '107'] as $status) {
    docCheck((new NfseNacional\NfseDocument(str_replace('<cStat>100</cStat>', '<cStat>' . $status . '</cStat>', $xml)))->number() === '123', 'TStat válido ' . $status);
}
docReject(static fn() => new NfseNacional\NfseDocument(str_replace('<cStat>100</cStat>', '<cStat>101</cStat>', $xml)), 'status não previsto rejeitado');
docReject(static fn() => new NfseNacional\NfseDocument(str_replace('<cStat>100</cStat>', '', $xml)), 'rejeita documento sem status de geração');
docReject(static fn() => new NfseNacional\NfseDocument(str_replace('<tpAmb>2</tpAmb>', '<tpAmb>3</tpAmb>', $xml)), 'ambiente inválido rejeitado');
docReject(static fn() => new NfseNacional\NfseDocument(str_replace('<nDPS>78</nDPS>', '<nDPS>79</nDPS>', $xml)), 'ID não pode divergir da numeração DPS');
docReject(static fn() => new NfseNacional\NfseDocument(str_replace('<nNFSe>123</nNFSe>', '<nNFSe>123</nNFSe><nNFSe>124</nNFSe>', $xml)), 'campo crítico duplicado rejeitado');
docReject(static fn() => new NfseNacional\NfseDocument(str_replace('</infNFSe>', '</infNFSe><infNFSe/>', $xml)), 'grupos duplicados rejeitados');
docReject(static fn() => new NfseNacional\NfseDocument(str_replace('http://www.sped.fazenda.gov.br/nfse', 'https://example.invalid/nfse', $xml)), 'namespace diferente rejeitado');
docReject(static fn() => new NfseNacional\NfseDocument('<root>' . preg_replace('/<\?xml[^>]+>/', '', $xml) . '</root>'), 'envelope externo não é NFS-e');
docReject(static fn() => new NfseNacional\NfseDocument(str_replace('<NFSe ', '<!DOCTYPE NFSe [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><NFSe ', $xml)), 'DOCTYPE e entidade externa bloqueados');
docReject(static fn() => new NfseNacional\NfseDocument(str_repeat('x', 5242881)), 'limite de 5 MB');
docReject(static fn() => $note->text('../secrets'), 'XPath externo rejeitado');
$production = new NfseNacional\NfseDocument(str_replace('<tpAmb>2</tpAmb>', '<tpAmb>1</tpAmb>', $xml));
docCheck(str_starts_with($production->consultationUrl(), 'https://www.nfse.gov.br/'), 'ambiente produção utiliza domínio correto');

final class DocumentFakeEmail
{
    public array $attachments = [];
    public array $recipients = [];
    public array $sent = [];
    public bool $accept = true;
    private string $subject = '';
    public function clear(bool $attachments = false): self { $this->recipients = []; if ($attachments) { $this->attachments = []; } return $this; }
    public function from($from, $name = ''): self { return $this; }
    public function to($to): self { $this->recipients[] = $to; return $this; }
    public function subject($subject): self { $this->subject = $subject; return $this; }
    public function message($message): self { return $this; }
    public function attach($bytes, $disposition, $name, $mime): self { $this->attachments[] = compact('bytes', 'disposition', 'name', 'mime'); return $this; }
    public function set_mailtype($type): self { return $this; }
    public function set_newline($newline): self { return $this; }
    public function send(): bool { $this->sent[] = ['recipients' => $this->recipients, 'attachments' => $this->attachments, 'subject' => $this->subject]; return $this->accept; }
}
final class DocumentFakeLoader { public function library(string $library): void {} }
$documentOptionReads = 0;
$documentSender = 'remetente@example.invalid';
$documentCi = (object) ['email' => new DocumentFakeEmail(), 'load' => new DocumentFakeLoader()];
function &get_instance() { return $GLOBALS['documentCi']; }
function get_option(string $option): string
{
    ++$GLOBALS['documentOptionReads'];
    return $option === 'smtp_email' ? $GLOBALS['documentSender'] : 'CANARY CADASTRO ATUAL ALTERADO';
}

$beforeReads = $documentOptionReads;
$diagnostics = NfseNacional\Danfe::diagnostics();
if (empty($diagnostics['available'])) {
    $pending = !empty($diagnostics['tcpdf_available']) && !is_file(NfseNacional\DanfeFonts::path() . 'manifest.json');
    fwrite(STDERR, ($pending ? 'PENDENTE: ' : 'FALHOU: ') . ($diagnostics['message'] ?? 'Recursos PDF indisponíveis.') . PHP_EOL
        . $documentChecks . " verificações de parser executadas; geração de PDF e anexação por e-mail não executadas.\n");
    exit($pending ? 2 : 1);
}
docCheck($diagnostics['tcpdf_available'] === true && isset($diagnostics['exact_fonts'], $diagnostics['message']), 'diagnóstico administrativo informa motor e fontes internos');
docCheck($diagnostics['exact_fonts'] === true && $diagnostics['label_font'] === 'arial' && $diagnostics['content_font'] === 'microsoftsansserif', 'gate PDF exige Arial e Microsoft Sans Serif originais verificadas');
$pdf = NfseNacional\Danfe::render($xml);
if ($documentOutput !== '') {
    if (!is_dir($documentOutput) && !mkdir($documentOutput, 0700, true)) { throw new RuntimeException('Não foi possível criar diretório de teste.'); }
    file_put_contents($documentOutput . '/danfse-sintetico.pdf', $pdf);
    file_put_contents($documentOutput . '/nfse-sintetica.xml', $xml);
}
docCheck(str_starts_with($pdf, '%PDF-') && strlen($pdf) > 5000, 'PDF real gerado offline sem TCPDF_FILE ou Perfex');
docCheck($documentOptionReads === $beforeReads, 'PDF não consulta configurações/cadastro atual do CRM');
docCheck(preg_match('~/Count\s+1\b~', $pdf) === 1, 'PDF possui uma única página');
docCheck(preg_match_all('~/FontFile(?:2|3)?\s+\d+\s+\d+\s+R\b~', $pdf) >= 2, 'fontes regular e negrito são incorporadas ao PDF');
docCheck(preg_match('~/BaseFont\s*/[A-Za-z0-9+_-]*Arial[A-Za-z0-9+_-]*~i', $pdf) === 1
    && preg_match('~/BaseFont\s*/[A-Za-z0-9+_-]*MicrosoftSansSerif[A-Za-z0-9+_-]*~i', $pdf) === 1
    && !preg_match('/LiberationSans|FreeSans/i', $pdf), 'PDF incorpora as famílias exigidas sem fonte substituta');
docCheck(str_contains($pdf, '/ToUnicode'), 'fontes possuem mapa Unicode para leitura e cópia');
[$streams, $unicodeMaps] = docPdfStreams($pdf);
foreach (['SEM VALIDADE', 'Prestação fictícia', 'R$ 1.000,00'] as $text) {
    docCheck(docPdfContains($text, $streams, $unicodeMaps), 'texto e símbolos preservados no PDF: ' . $text);
}
docCheck(!docPdfContains('CANARY', $streams, $unicodeMaps), 'cadastro atual não contamina documento autorizado');

$longName = mb_substr(str_repeat('CONSULTORIA EMPRESARIAL FICTÍCIA PARA TESTES ', 5), 0, 150);
$longDescription = mb_substr(str_repeat('Atendimento técnico fictício, revisão de cadastros e conferência de informações, sem transmissão fiscal. ', 24), 0, 1987) . ' FIMDESCRICAO';
$longXml = str_replace(['PRESTADOR FICTICIO DO XML', $note->dpsText('serv/cServ/xDescServ')], [$longName, $longDescription], $xml);
$longPdf = NfseNacional\Danfe::render($longXml);
[$longStreams, $longMaps] = docPdfStreams($longPdf);
docCheck(preg_match('~/Count\s+1\b~', $longPdf) === 1, 'razão social de 150 caracteres e descrição longa mantêm uma página');
docCheck(docPdfContains('CONSULTORIA EMPRESARIAL FICTÍCIA', $longStreams, $longMaps), 'razão social longa preserva caracteres acentuados no espaço reservado');
docCheck(docPdfContains('FIMDESCRICAO', $longStreams, $longMaps), 'descrição de aproximadamente 2000 caracteres permanece legível até o final');
if ($documentOutput !== '') {
    file_put_contents($documentOutput . '/danfse-textos-longos.pdf', $longPdf);
    file_put_contents($documentOutput . '/nfse-textos-longos.xml', $longXml);
}

$documentCi->email->recipients = ['stale@example.invalid'];
$documentCi->email->attachments = [['bytes' => 'private previous message']];
NfseNacional\Mailer::assertReady();
docCheck($documentCi->email->sent === [] && $documentCi->email->recipients === ['stale@example.invalid'], 'pré-validação de SMTP não envia nem altera destinatários');
$documentSender = '';
docReject(static fn() => NfseNacional\Mailer::assertReady(), 'remetente ausente bloqueia antes da tentativa SMTP');
$documentSender = 'remetente@example.invalid';
$nativeMail = $documentCi->email;
$documentCi->email = new stdClass();
docReject(static fn() => NfseNacional\Mailer::assertReady(), 'engine incompleto bloqueia antes da tentativa SMTP');
$documentCi->email = $nativeMail;
NfseNacional\Mailer::send('tomador@example.invalid', $xml, $pdf);
$sent = $documentCi->email->sent[0];
docCheck($sent['recipients'] === ['tomador@example.invalid'], 'destinatário anterior não é reutilizado');
docCheck(count($sent['attachments']) === 2, 'somente XML e PDF solicitados são anexados');
docCheck($sent['attachments'][0]['bytes'] === $xml && $sent['attachments'][1]['bytes'] === $pdf, 'anexos recebidos em memória sem transformação');
docCheck($sent['attachments'][0]['mime'] === 'application/xml' && $sent['attachments'][1]['mime'] === 'application/pdf', 'MIME explícito para conteúdo em memória');
docCheck($documentCi->email->recipients === [] && $documentCi->email->attachments === [], 'dados do envio são limpos ao final');
docCheck(str_starts_with($sent['subject'], '[HOMOLOGAÇÃO]'), 'e-mail de teste identifica ambiente');
$documentCi->email->accept = false;
docReject(static fn() => NfseNacional\Mailer::send('tomador@example.invalid', $xml, $pdf), 'SMTP sem confirmação não é marcado como sucesso');
docCheck(count($documentCi->email->sent) === 2, 'nenhuma repetição automática após falha SMTP');
docReject(static fn() => NfseNacional\Mailer::send("recipient@example.invalid\r\nBcc: intruder@example.invalid", $xml, $pdf), 'injeção de cabeçalho bloqueada');
docReject(static fn() => NfseNacional\Mailer::send('tomador@example.invalid', $xml, 'not a PDF'), 'anexo PDF inválido rejeitado antes do SMTP');
echo 'OK: ' . $documentChecks . " verificações de documento, PDF e e-mail offline.\n";
