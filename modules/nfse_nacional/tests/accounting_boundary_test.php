<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
require dirname(__DIR__) . '/autoload.php';

use NfseNacional\Config;
use NfseNacional\Mailer;
use NfseNacional\ZipBuilder;

$checks = 0;
function accountingCheck(bool $condition, string $label): void {
    if (!$condition) { throw new RuntimeException('FALHOU: ' . $label); } ++$GLOBALS['checks'];
}
function accountingRefuses(callable $operation, string $label, ?callable $inspect = null): Throwable {
    try { $operation(); } catch (Throwable $error) { accountingCheck($inspect === null || $inspect($error), $label); return $error; }
    throw new RuntimeException('FALHOU: ' . $label);
}

// Save/add/edit/reorder/delete uses the same Config boundary as the native settings form.
$base = Config::sanitize(['environment' => '2', 'series' => '25', 'next_number' => '709', 'use_local_certificate' => '1',
    'provider' => ['tax_id' => '11222333000181', 'name' => 'Empresa atual', 'municipality_code' => '3550308', 'state' => 'SP', 'city' => 'São Paulo',
        'street' => 'Praça da Sé', 'zip' => '01001000', 'number' => '1'],
    'service' => ['national_code' => '010101', 'description' => 'Padrão da empresa'], 'tax' => ['iss_rate' => '2.50']]);
$first = Config::sanitize(['accounting_recipients' => [
    ['name' => '  Contabilidade São José  ', 'email' => '  Contabil@example.invalid  ', 'provider' => ['tax_id' => 'UNTRUSTED'], 'api_url' => 'https://example.invalid'],
    ['name' => 'Conferência', 'email' => 'auditoria@example.invalid'],
]], $base);
$rows = $first['accounting_recipients'];
accountingCheck(count($rows) === 2 && preg_match('/^[a-f0-9]{16}$/D', $rows[0]['id']) && preg_match('/^[a-f0-9]{16}$/D', $rows[1]['id'])
    && $rows[0]['id'] !== $rows[1]['id'], 'new contacts receive distinct opaque server-generated IDs');
accountingCheck($rows[0]['name'] === 'Contabilidade São José' && $rows[0]['email'] === 'Contabil@example.invalid'
    && array_keys($rows[0]) === ['id', 'name', 'email'], 'valid contact data is trimmed and untrusted extra fields are discarded');
$unrelated = $first; unset($unrelated['accounting_recipients']); $baseUnrelated = $base; unset($baseUnrelated['accounting_recipients']);
accountingCheck($unrelated === $baseUnrelated, 'saving recipients does not modify issuer, certificate preference, fiscal settings or sequence');
accountingCheck(Config::sanitize(['provider' => ['email' => 'empresa@example.invalid']], $first)['accounting_recipients'] === $rows,
    'saving another settings section preserves the saved recipient list');
$edited = Config::sanitize(['accounting_recipients' => [$rows[1], array_replace($rows[0], ['name' => 'Escritório revisado', 'email' => 'revisado@example.invalid'])]], $first);
accountingCheck(array_column($edited['accounting_recipients'], 'id') === [$rows[1]['id'], $rows[0]['id']]
    && $edited['accounting_recipients'][1]['email'] === 'revisado@example.invalid', 'edit and reordering preserve IDs of this company');
$added = Config::sanitize(['accounting_recipients' => [...$edited['accounting_recipients'], ['name' => 'Terceiro', 'email' => 'terceiro@example.invalid']]], $edited);
accountingCheck(count($added['accounting_recipients']) === 3 && !in_array($added['accounting_recipients'][2]['id'], array_column($rows, 'id'), true), 'adding a contact preserves prior identities and creates one new identity');
$deleted = Config::sanitize(['accounting_recipients' => [$added['accounting_recipients'][2]]], $added);
accountingCheck($deleted['accounting_recipients'] === [$added['accounting_recipients'][2]], 'omitted rows are deleted instead of recursively merged back');
accountingRefuses(fn() => Config::sanitize(['accounting_recipients' => [$rows[0]]], $deleted), 'deleted stale ID cannot be reintroduced without a new server identity');
accountingRefuses(fn() => Config::sanitize(['accounting_recipients' => [$rows[0]]], $base), 'ID belonging to another company list is not accepted');
accountingCheck(Config::sanitize(['accounting_recipients' => []], $added)['accounting_recipients'] === [], 'explicit empty list deletes every recipient');
accountingCheck(Config::sanitize(['accounting_recipients' => [['name' => '', 'email' => '']]], $added)['accounting_recipients'] === [], 'empty form row does not create a recipient');
accountingRefuses(fn() => Config::sanitize(['accounting_recipients' => [$rows[0], array_replace($rows[0], ['email' => 'different@example.invalid'])]], $first), 'same saved ID cannot represent two submitted contacts');
accountingRefuses(fn() => Config::sanitize(['accounting_recipients' => [['name' => 'A', 'email' => 'Same@example.invalid'], ['name' => 'B', 'email' => ' same@example.invalid ']]], $first), 'duplicate addresses are rejected case-insensitively after trimming');

foreach ([
    'missing name' => ['email' => 'valid@example.invalid'], 'missing email' => ['name' => 'Contabilidade'],
    'display-name address' => ['name' => 'Contabilidade', 'email' => 'Pessoa <valid@example.invalid>'],
    'multiple addresses' => ['name' => 'Contabilidade', 'email' => 'a@example.invalid,b@example.invalid'],
    'header injection' => ['name' => 'Contabilidade', 'email' => "valid@example.invalid\r\nBcc: another@example.invalid"],
    'embedded CR address' => ['name' => 'Contabilidade', 'email' => "conta\rbil@example.invalid"],
    'embedded NUL address' => ['name' => 'Contabilidade', 'email' => "conta\0bil@example.invalid"],
    'embedded CR name' => ['name' => "Contabili\rdade", 'email' => 'valid@example.invalid'],
    'control in saved ID' => ['id' => $rows[0]['id'] . "\r", 'name' => 'Contabilidade', 'email' => 'valid@example.invalid'],
    'multiline name' => ['name' => "Nome\nOutro", 'email' => 'valid@example.invalid'],
    'structured email' => ['name' => 'Contabilidade', 'email' => ['valid@example.invalid']],
    'boolean name' => ['name' => false, 'email' => 'valid@example.invalid'],
    'unknown ID' => ['id' => 'ffffffffffffffff', 'name' => 'Contabilidade', 'email' => 'valid@example.invalid'],
    'long ID' => ['id' => str_repeat('a', 17), 'name' => 'Contabilidade', 'email' => 'valid@example.invalid'],
] as $label => $bad) { accountingRefuses(fn() => Config::sanitize(['accounting_recipients' => [$bad]], $first), 'invalid settings contact: ' . $label); }
foreach ([null, false, 'invalid', [['name' => 'A', 'email' => 'valid@example.invalid'], 'invalid']] as $bad) {
    accountingRefuses(fn() => Config::sanitize(['accounting_recipients' => $bad], $first), 'invalid list/container is rejected');
}
$ten = []; for ($i = 0; $i < 10; ++$i) { $ten[] = ['name' => 'Escritório ' . $i, 'email' => 'contato' . $i . '@example.invalid']; }
accountingCheck(count(Config::sanitize(['accounting_recipients' => $ten], $base)['accounting_recipients']) === 10, 'ten saved recipients are supported');
accountingCheck(count(Config::sanitize(['accounting_recipients' => [...$ten, ['name' => 'Extra', 'email' => 'extra@example.invalid']]], $base)['accounting_recipients']) === 11, 'more than ten recipients supported without truncation');
$maxEmail = str_repeat('a', 64) . '@' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 53) . '.invalid';
$maximum = Config::sanitize(['accounting_recipients' => [['name' => str_repeat('á', 100), 'email' => $maxEmail]]], $base);
accountingCheck(strlen($maxEmail) === 254 && $maximum['accounting_recipients'][0]['email'] === $maxEmail
    && mb_strlen($maximum['accounting_recipients'][0]['name']) === 100, 'UTF-8 name and valid email boundaries preserve all characters');
accountingRefuses(fn() => Config::sanitize(['accounting_recipients' => [['name' => str_repeat('á', 101), 'email' => 'valid@example.invalid']]], $base), 'overlong UTF-8 name is rejected instead of truncated');
accountingRefuses(fn() => Config::sanitize(['accounting_recipients' => [['name' => 'Nome', 'email' => 'a' . $maxEmail]]], $base), 'overlong email is rejected instead of truncated');
accountingCheck($base['accounting_recipients'] === [] && $first['accounting_recipients'] === $rows, 'invalid saves leave previous configuration values untouched');

// Real module ZIP with a native mail spy. No mail transport implementation exists here.
final class AccountingNativeMail {
    public array $calls = []; public array $sent = []; public array $state = ['to' => ['stale@example.invalid'], 'attachments' => ['stale-private-attachment']];
    public bool $sendResult = true; public ?string $failAt = null;
    private function record(string $method, array $args): void {
        $this->calls[] = [$method, $args];
        if ($this->failAt === $method) { throw new RuntimeException('PRIVATE_NATIVE_SMTP_CANARY'); }
    }
    public function clear(bool $attachments = false): void { $this->record('clear', [$attachments]); $this->state = ['to' => [], 'attachments' => []]; }
    public function set_mailtype(string $type): void { $this->record('set_mailtype', [$type]); $this->state['mailtype'] = $type; }
    public function set_newline(string $value): void { $this->record('set_newline', [$value]); $this->state['newline'] = $value; }
    public function from(string $email, string $name = ''): void { $this->record('from', [$email, $name]); $this->state['from'] = [$email, $name]; }
    public function to($emails): void { $this->record('to', [$emails]); $this->state['to'] = (array) $emails; }
    public function subject(string $subject): void { $this->record('subject', [$subject]); $this->state['subject'] = $subject; }
    public function message(string $message): void { $this->record('message', [$message]); $this->state['message'] = $message; }
    public function attach(string $bytes, string $disposition, string $filename, string $mime): void {
        $this->record('attach', [strlen($bytes), $disposition, $filename, $mime]); $this->state['attachments'][] = [$bytes, $disposition, $filename, $mime];
    }
    public function send(): bool { $this->record('send', []); $this->sent[] = $this->state; return $this->sendResult; }
}
final class AccountingNativeLoader {
    public int $calls = 0; public bool $fail = false;
    public function library(string $name): void { ++$this->calls; if ($name !== 'email' || $this->fail) { throw new RuntimeException('Native loader unavailable'); } }
}
$accountingOptions = ['smtp_email' => 'emissor@example.invalid', 'companyname' => "Empresa\r\nAtual"];
$accountingCi = (object) ['load' => new AccountingNativeLoader(), 'email' => new AccountingNativeMail()];
function &get_instance() { return $GLOBALS['accountingCi']; }
function get_option(string $key) { return $GLOBALS['accountingOptions'][$key] ?? ''; }
$zipBuilder = new ZipBuilder(); $xml = '<documento>Fixture fiscal offline com acentuação.</documento>';
$zipBuilder->add('Emitidas/NFSe-offline.xml', $xml); $zipBuilder->add('manifesto.csv', "Origem;Arquivo\r\nOffline;Emitidas/NFSe-offline.xml\r\n");
$zip = $zipBuilder->finish(); $zipHash = hash('sha256', $zip); $filename = 'NFSe_2026-11_Homologacao_XML.zip';
$mail = $accountingCi->email; $initialState = $mail->state;
Mailer::assertReady();
accountingCheck($mail->calls === [] && $mail->sent === [] && $mail->state === $initialState, 'readiness check does not clear or send a native message');
foreach (['2', '1'] as $environment) {
    $accountingCi->email = $mail = new AccountingNativeMail();
    // Only addresses from the successfully saved settings enter the send boundary.
    Mailer::sendPackage(array_column($first['accounting_recipients'], 'email'), $zip, $filename, '2026-11', $environment);
    $message = $mail->sent[0];
    accountingCheck(count($mail->sent) === 1 && $message['to'] === array_column($rows, 'email') && !in_array('stale@example.invalid', $message['to'], true), 'native engine receives only selected configured recipients: environment ' . $environment);
    accountingCheck(count($message['attachments']) === 1 && $message['attachments'][0] === [$zip, 'attachment', $filename, 'application/zip']
        && hash('sha256', $message['attachments'][0][0]) === $zipHash, 'single ZIP attachment keeps filename, MIME type and every byte: environment ' . $environment);
    accountingCheck($message['from'] === ['emissor@example.invalid', 'Empresa  Atual'] && $message['mailtype'] === 'text' && $message['newline'] === "\r\n",
        'sender and plain-text message use native company configuration without header injection: environment ' . $environment);
    accountingCheck(str_contains($message['subject'], '11/2026') && str_contains($message['message'], '11/2026')
        && (str_contains($message['subject'], '[HOMOLOGAÇÃO]') === ($environment === '2'))
        && (str_contains($message['message'], 'sem validade jurídica') === ($environment === '2')), 'subject/body identify competence and distinguish homologation from production: environment ' . $environment);
    accountingCheck($mail->calls[0] === ['clear', [true]] && end($mail->calls) === ['clear', [true]]
        && count(array_filter($mail->calls, static fn($call) => $call[0] === 'clear')) === 2 && $mail->state === ['to' => [], 'attachments' => []],
        'native recipients and private attachments are cleared before and after sending: environment ' . $environment);
}
$accountingCi->email = $mail = new AccountingNativeMail();
Mailer::sendPackage(['Same@example.invalid', 'same@example.invalid', 'other@example.invalid'], $zip, $filename, '2026-11', '2');
accountingCheck(count($mail->sent[0]['to']) === 2 && array_map('strtolower', $mail->sent[0]['to']) === ['same@example.invalid', 'other@example.invalid'], 'same address is not sent twice because of case differences');

$accountingCi->email = $mail = new AccountingNativeMail();
Mailer::sendPackage(array_column([...$ten, ['email' => 'extra@example.invalid']], 'email'), $zip, $filename, '2026-11', '2');
accountingCheck(count($mail->sent[0]['to']) === 11, 'native mailer accepts more than ten selected recipients');

$badCalls = [
    'empty recipients' => [[], $zip, $filename, '2026-11', '2'],
    'header injection recipient' => [["valid@example.invalid\r\nBcc: other@example.invalid"], $zip, $filename, '2026-11', '2'],
    'invalid recipient type' => [[['email' => 'valid@example.invalid']], $zip, $filename, '2026-11', '2'],
    'display-name recipient' => [['Pessoa <valid@example.invalid>'], $zip, $filename, '2026-11', '2'],
    'empty ZIP' => [['valid@example.invalid'], '', $filename, '2026-11', '2'],
    'non-ZIP attachment' => [['valid@example.invalid'], '%PDF-offline', $filename, '2026-11', '2'],
    'truncated archive' => [['valid@example.invalid'], substr($zip, 0, -22), $filename, '2026-11', '2'],
    'ZIP magic without archive' => [['valid@example.invalid'], "PK\x03\x04", $filename, '2026-11', '2'],
    'inconsistent ZIP directory' => [['valid@example.invalid'], substr_replace($zip, pack('V', strlen($zip)), -6, 4), $filename, '2026-11', '2'],
    'path traversal filename' => [['valid@example.invalid'], $zip, '../documentos.zip', '2026-11', '2'],
    'header injection filename' => [['valid@example.invalid'], $zip, "documentos.zip\r\nX: test", '2026-11', '2'],
    'wrong extension' => [['valid@example.invalid'], $zip, 'documentos.php', '2026-11', '2'],
    'invalid month' => [['valid@example.invalid'], $zip, $filename, '2026-13', '2'],
    'invalid environment' => [['valid@example.invalid'], $zip, $filename, '2026-11', '3'],
];
foreach ($badCalls as $label => $arguments) {
    $accountingCi->email = $mail = new AccountingNativeMail(); $loads = $accountingCi->load->calls;
    accountingRefuses(fn() => Mailer::sendPackage(...$arguments), 'invalid mail boundary rejected: ' . $label, static fn($error) => $error instanceof InvalidArgumentException);
    accountingCheck($mail->sent === [] && $mail->calls === [] && $accountingCi->load->calls === $loads, 'invalid mail has no native state/SMTP side effects: ' . $label);
}

$largeZipBuilder = new ZipBuilder(); $largeZipBuilder->add('documento.xml', random_bytes(20971521)); $largeZip = $largeZipBuilder->finish();
$accountingCi->email = $mail = new AccountingNativeMail();
accountingCheck(strlen($largeZip) > 20971520, 'size-boundary fixture is an actual ZIP exceeding 20 MiB');
accountingRefuses(fn() => Mailer::sendPackage(['valid@example.invalid'], $largeZip, $filename, '2026-11', '2'), 'valid oversized ZIP is rejected before SMTP');
accountingCheck($mail->calls === [] && $mail->sent === [], 'oversized archive does not touch native mail state');
unset($largeZip, $largeZipBuilder);

foreach (['false', 'exception', 'attach'] as $failure) {
    $accountingCi->email = $mail = new AccountingNativeMail();
    if ($failure === 'false') { $mail->sendResult = false; } else { $mail->failAt = $failure === 'exception' ? 'send' : 'attach'; }
    accountingRefuses(fn() => Mailer::sendPackage(['valid@example.invalid'], $zip, $filename, '2026-11', '2'), 'native failure is inconclusive: ' . $failure,
        static fn($error) => $error instanceof RuntimeException && str_contains($error->getMessage(), 'sem confirmação') && !str_contains($error->getMessage(), 'PRIVATE_NATIVE_SMTP_CANARY'));
    accountingCheck($mail->calls[0] === ['clear', [true]] && end($mail->calls) === ['clear', [true]] && $mail->state === ['to' => [], 'attachments' => []],
        'finally clears all private native mail state after ' . $failure);
    accountingCheck(count(array_filter($mail->calls, static fn($call) => $call[0] === 'send')) === ($failure === 'attach' ? 0 : 1), 'native failure never causes automatic SMTP retry: ' . $failure);
}
$accountingCi->email = $mail = new AccountingNativeMail(); $accountingOptions['smtp_email'] = '';
accountingRefuses(fn() => Mailer::sendPackage(['valid@example.invalid'], $zip, $filename, '2026-11', '2'), 'missing native sender prevents sending');
accountingCheck($mail->calls === [], 'missing sender does not clear or attach another native message');
$accountingOptions['smtp_email'] = 'emissor@example.invalid'; $accountingCi->load->fail = true;
accountingRefuses(fn() => Mailer::sendPackage(['valid@example.invalid'], $zip, $filename, '2026-11', '2'), 'unavailable native loader prevents sending');
accountingCheck($mail->calls === [], 'loader failure cannot leave private package attachments');
$accountingCi->load->fail = false; $accountingCi->email = new stdClass();
accountingRefuses(fn() => Mailer::sendPackage(['valid@example.invalid'], $zip, $filename, '2026-11', '2'), 'incomplete native email engine is rejected');

echo 'OK: ' . $checks . " verificações de destinatários e Mailer contábil. Config/ZIP/Mailer reais; sem SMTP ou rede.\n";
