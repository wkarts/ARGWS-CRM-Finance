<?php
declare(strict_types=1);

namespace NfseNacional;

final class Mailer
{
    /** Check configuration before recording an SMTP attempt. Does not send or clear messages. */
    public static function assertReady(): void
    {
        self::sender();
        self::nativeMailer();
    }

    /** Uses the current company's native Perfex email library/configuration.
     * Attachments remain in memory; no public or temporary fiscal files are created.
     */
    public static function send(string $recipientEmail, string $xml, string $pdf): void
    {
        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) || strpbrk($recipientEmail, "\r\n") !== false) {
            throw new \InvalidArgumentException('O e-mail do destinatário é inválido.');
        }
        $note = new NfseDocument($xml);
        if (strncmp($pdf, '%PDF-', 5) !== 0 || strlen($pdf) > 20971520) {
            throw new \InvalidArgumentException('O DANFSe não contém um PDF válido para envio.');
        }
        $from = self::sender();
        $mail = self::nativeMailer();
        $label = trim((string) get_option('companyname'));
        $label = str_replace(["\r", "\n"], ' ', $label);
        $subject = ($note->environment() === '2' ? '[HOMOLOGAÇÃO] ' : '') . 'NFS-e ' . $note->number();
        $message = "Segue a NFS-e nº " . $note->number() . ", acompanhada do XML e do DANFSe.\n\n";
        if ($note->environment() === '2') { $message .= "NFS-e SEM VALIDADE JURÍDICA - emitida em ambiente de testes.\n\n"; }
        $message .= 'Consulta da autenticidade: ' . $note->consultationUrl();
        $mail->clear(true);
        try {
            if (is_callable([$mail, 'set_mailtype'])) { $mail->set_mailtype('text'); }
            if (is_callable([$mail, 'set_newline'])) { $mail->set_newline("\r\n"); }
            $mail->from($from, $label);
            $mail->to($recipientEmail);
            $mail->subject($subject);
            $mail->message($message);
            $mail->attach($xml, 'attachment', 'NFSe-' . $note->accessKey() . '.xml', 'application/xml');
            $mail->attach($pdf, 'attachment', 'DANFSe-' . $note->accessKey() . '.pdf', 'application/pdf');
            if ($mail->send() !== true) {
                throw new \RuntimeException('O servidor de e-mail não confirmou o envio. Verifique o registro SMTP antes de reenviar para evitar duplicidade.');
            }
        } catch (\Throwable $error) {
            throw new \RuntimeException('O envio ficou sem confirmação. Confira o servidor de e-mail do Perfex antes de reenviar.', 0, $error);
        } finally {
            // Clear recipients and attachments so another native message cannot reuse them.
            $mail->clear(true);
        }
    }

    /** The recipient list was selected from company settings; every address is validated again. */
    public static function sendPackage(array $emails, string $zip, string $filename, string $month, string $environment): void
    {
        if (!$emails) { throw new \InvalidArgumentException('Selecione os destinatários para o pacote mensal.'); }
        $recipients = [];
        foreach ($emails as $email) {
            if (!is_string($email) || strpbrk($email, "\r\n") !== false || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('O e-mail de um destinatário é inválido.');
            }
            $recipients[strtolower($email)] = $email;
        }
        if (strlen($zip) > 20971520 || !self::packageZipStructure($zip)
            || !preg_match('/^[A-Za-z0-9._-]{1,150}\.zip$/D', $filename)) {
            throw new \InvalidArgumentException('O pacote mensal é inválido ou ultrapassa 20 MB para envio por e-mail. Baixe o ZIP para encaminhar.');
        }
        if (!preg_match('/^[0-9]{4}-(?:0[1-9]|1[0-2])$/D', $month) || !in_array($environment, ['1', '2'], true)) {
            throw new \InvalidArgumentException('Competência ou ambiente do pacote inválido.');
        }
        $from = self::sender(); $mail = self::nativeMailer();
        $label = str_replace(["\r", "\n"], ' ', trim((string) get_option('companyname')));
        $subject = ($environment === '2' ? '[HOMOLOGAÇÃO] ' : '') . 'Documentos fiscais NFS-e — ' . substr($month, 5, 2) . '/' . substr($month, 0, 4);
        $message = "Segue o pacote de documentos fiscais da competência " . substr($month, 5, 2) . '/' . substr($month, 0, 4)
            . ".\n\nO ZIP contém os documentos selecionados e a relação dos arquivos incluídos.\n";
        if ($environment === '2') { $message .= "\nAMBIENTE DE HOMOLOGAÇÃO — documentos sem validade jurídica.\n"; }
        $mail->clear(true);
        try {
            if (is_callable([$mail, 'set_mailtype'])) { $mail->set_mailtype('text'); }
            if (is_callable([$mail, 'set_newline'])) { $mail->set_newline("\r\n"); }
            $mail->from($from, $label);
            $mail->to(array_values($recipients));
            $mail->subject($subject); $mail->message($message);
            $mail->attach($zip, 'attachment', $filename, 'application/zip');
            if ($mail->send() !== true) { throw new \RuntimeException('O servidor de e-mail não confirmou o envio do pacote.'); }
        } catch (\Throwable $error) {
            throw new \RuntimeException('O envio do pacote ficou sem confirmação. Confira os registros do servidor de e-mail antes de tentar novamente.', 0, $error);
        } finally { $mail->clear(true); }
    }

    /** Check the bounded, single-disk ZIP format produced by this module's ZipBuilder. */
    private static function packageZipStructure(string $zip): bool
    {
        $length = strlen($zip);
        if ($length < 98 || substr($zip, 0, 4) !== "PK\x03\x04" || substr($zip, -22, 4) !== "PK\x05\x06") { return false; }
        $end = unpack('Vsignature/vdisk/vstart_disk/ventries_disk/ventries/Vdirectory_size/Vdirectory_offset/vcomment', substr($zip, -22));
        if ($end['disk'] !== 0 || $end['start_disk'] !== 0 || $end['comment'] !== 0 || $end['entries'] < 1 || $end['entries'] > 65534
            || $end['entries_disk'] !== $end['entries'] || $end['directory_offset'] + $end['directory_size'] !== $length - 22
            || $end['directory_size'] < $end['entries'] * 46) { return false; }
        $directory = $end['directory_offset']; $localOffset = 0; $uncompressed = 0; $names = [];
        for ($i = 0; $i < $end['entries']; ++$i) {
            if ($directory + 46 > $length - 22) { return false; }
            $entry = unpack('Vsignature/vmade/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vpacked/Vsize/vname/vextra/vcomment/vdisk/vinternal/Vexternal/Voffset', substr($zip, $directory, 46));
            if ($entry['signature'] !== 0x02014b50 || $entry['needed'] !== 20 || $entry['flags'] !== 0x0800
                || !in_array($entry['method'], [0, 8], true) || $entry['disk'] !== 0 || $entry['extra'] !== 0 || $entry['comment'] !== 0
                || $entry['name'] < 1 || $entry['name'] > 512 || $entry['offset'] !== $localOffset
                || $directory + 46 + $entry['name'] > $length - 22 || $localOffset + 30 > $end['directory_offset']) { return false; }
            $name = substr($zip, $directory + 46, $entry['name']);
            if (isset($names[$name]) || !preg_match('//u', $name) || preg_match('~(?:^/|\\\\|[\x00-\x1f\x7f]|(?:^|/)\.\.?(/|$))~', $name)
                || str_contains($name, ':') || str_ends_with($name, '/')) { return false; }
            $local = unpack('Vsignature/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vpacked/Vsize/vname/vextra', substr($zip, $localOffset, 30));
            if ($local['signature'] !== 0x04034b50 || $local['extra'] !== 0) { return false; }
            foreach (['needed', 'flags', 'method', 'time', 'date', 'crc', 'packed', 'size', 'name'] as $field) {
                if ($local[$field] !== $entry[$field]) { return false; }
            }
            $payloadOffset = $localOffset + 30 + $local['name'];
            if (substr($zip, $localOffset + 30, $local['name']) !== $name || $payloadOffset + $local['packed'] > $end['directory_offset']
                || ($local['method'] === 0 && $local['packed'] !== $local['size'])) { return false; }
            $uncompressed += $local['size'];
            if ($uncompressed > 104857600) { return false; }
            $names[$name] = true; $localOffset = $payloadOffset + $local['packed']; $directory += 46 + $entry['name'];
        }
        return $localOffset === $end['directory_offset'] && $directory === $length - 22;
    }

    private static function sender(): string
    {
        if (!function_exists('get_instance') || !function_exists('get_option')) {
            throw new \RuntimeException('O envio deve ser realizado dentro do Perfex autenticado.');
        }
        $from = trim((string) get_option('smtp_email'));
        if (!filter_var($from, FILTER_VALIDATE_EMAIL) || strpbrk($from, "\r\n") !== false) {
            throw new \RuntimeException('Configure o remetente de e-mail em Configurações > E-mail do Perfex.');
        }
        return $from;
    }

    private static function nativeMailer(): object
    {
        if (!function_exists('get_instance')) {
            throw new \RuntimeException('O envio deve ser realizado dentro do Perfex autenticado.');
        }
        $ci = &get_instance();
        if (!is_object($ci) || !isset($ci->load) || !is_callable([$ci->load, 'library'])) {
            throw new \RuntimeException('O carregador nativo de e-mail não está disponível no Perfex.');
        }
        // CI_Loader initializes the selected native engine from application/config/email.php,
        // preserving SMTP/OAuth and encrypted-password handling implemented by Perfex.
        try { $ci->load->library('email'); }
        catch (\Throwable $error) { throw new \RuntimeException('Não foi possível inicializar o e-mail configurado no Perfex.', 0, $error); }
        $mail = $ci->email ?? null;
        foreach (['clear', 'from', 'to', 'subject', 'message', 'attach', 'send'] as $method) {
            if (!is_object($mail) || !is_callable([$mail, $method])) {
                throw new \RuntimeException('A biblioteca nativa de e-mail não está disponível no Perfex.');
            }
        }
        return $mail;
    }
}
