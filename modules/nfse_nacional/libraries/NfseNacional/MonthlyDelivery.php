<?php
declare(strict_types=1);
namespace NfseNacional;

/** Explicit accounting-package delivery, with durable protection against duplicate SMTP attempts. */
final class MonthlyDelivery
{
    private NationalRepository $repository;
    private \Closure $sender;
    private string $prefix;

    public function __construct(private $model, ?callable $sender = null)
    {
        $this->repository = new NationalRepository($model); $this->prefix = db_prefix();
        $this->sender = $sender ? \Closure::fromCallable($sender)
            : static fn(array $emails, string $zip, string $filename, string $month, string $environment) => Mailer::sendPackage($emails, $zip, $filename, $month, $environment);
    }

    public static function schema(): array
    {
        return ['accounting_deliveries' => "`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `issuer_tax_id` VARCHAR(14) NOT NULL, `environment` CHAR(1) NOT NULL, `month` CHAR(7) NOT NULL,
            `direction` VARCHAR(8) NOT NULL, `format` VARCHAR(4) NOT NULL,
            `fingerprint` CHAR(64) NOT NULL, `recipients_hash` CHAR(64) NOT NULL,
            `recipients_enc` LONGTEXT NOT NULL, `package_sha256` CHAR(64) NOT NULL, `package_size` INT UNSIGNED NOT NULL,
            `file_name` VARCHAR(191) NOT NULL, `document_count` INT UNSIGNED NOT NULL,
            `status` VARCHAR(16) NOT NULL, `last_error` TEXT NULL, `created_by` INT NOT NULL,
            `created_at` DATETIME NOT NULL, `sent_at` DATETIME NULL, `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `delivery_identity` (`issuer_tax_id`,`environment`,`fingerprint`,`recipients_hash`),
            KEY `issuer_created` (`issuer_tax_id`,`created_at`)"];
    }

    public function send(array $filters, string $fingerprint, array $recipientIds): array
    {
        $issuer = $this->repository->contextTaxId(); $this->assertSend();
        $filters = MonthlyPackage::filters($filters); $recipients = $this->recipients($recipientIds);
        $emails = array_column($recipients, 'email'); $recipientHash = hash('sha256', json_encode($emails, JSON_THROW_ON_ERROR));
        // Build and validate everything first. A generation/configuration failure is
        // not an SMTP attempt and must never leave a misleading "sending" record.
        $package = (new MonthlyPackage($this->model, $this->repository))->build($filters, $fingerprint);
        if (strlen($package['bytes']) > 20971520) { throw new \RuntimeException('O pacote ultrapassa 20 MB para envio por e-mail. Baixe o ZIP e encaminhe pelo canal escolhido. Nenhuma mensagem foi enviada.'); }
        Mailer::assertReady();
        return $this->model->lock('accounting:' . $issuer . ':' . $fingerprint . ':' . $recipientHash,
            function () use ($issuer, $filters, $fingerprint, $recipientHash, $recipients, $recipientIds, $emails, $package): array {
                $this->assertSend();
                if (!hash_equals($issuer, $this->repository->contextTaxId())) { throw new \RuntimeException('O emitente mudou. Confira novamente o pacote.'); }
                $currentRecipients = $this->recipients($recipientIds);
                if ($currentRecipients !== $recipients) { throw new \RuntimeException('Os destinatários cadastrados mudaram. Confira a seleção antes de enviar.'); }
                $current = (new MonthlyPackage($this->model, $this->repository))->report($filters);
                if (!hash_equals($fingerprint, $current['fingerprint'])) { throw new \RuntimeException('Os documentos do mês mudaram. Confira novamente a composição antes de enviar.'); }
                $existing = $this->model->db->where('issuer_tax_id', $issuer)->where('environment', $filters['environment'])
                    ->where('fingerprint', $fingerprint)->where('recipients_hash', $recipientHash)->get($this->table());
                if (!$existing) { throw new \RuntimeException('O histórico de envio está indisponível. Nenhuma mensagem foi enviada.'); }
                $existing = $existing->row_array();
                if ($existing) {
                    if ($existing['status'] === 'sent') { return ['id' => (int) $existing['id'], 'status' => 'sent', 'already_sent' => true, 'message' => 'Este pacote já foi enviado aos mesmos destinatários.']; }
                    throw new \RuntimeException('Já existe uma tentativa deste pacote sem confirmação. Confira o servidor SMTP e o histórico antes de qualquer reenvio.');
                }
                $now = gmdate('Y-m-d H:i:s');
                $row = ['issuer_tax_id' => $issuer, 'environment' => $filters['environment'], 'month' => $filters['month'],
                    'direction' => $filters['direction'], 'format' => $filters['format'], 'fingerprint' => $fingerprint,
                    'recipients_hash' => $recipientHash, 'recipients_enc' => $this->model->crypto()->encrypt(json_encode($recipients, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
                    'package_sha256' => $package['sha256'], 'package_size' => strlen($package['bytes']), 'file_name' => $package['filename'],
                    'document_count' => $package['count'], 'status' => 'sending', 'last_error' => null,
                    'created_by' => (int) get_staff_user_id(), 'created_at' => $now, 'sent_at' => null, 'updated_at' => $now];
                if ($this->model->db->insert($this->table(), $row) === false) { throw new \RuntimeException('Não foi possível registrar o envio. Nenhuma mensagem foi enviada.'); }
                $id = (int) $this->model->db->insert_id();
                if ($id < 1) { throw new \RuntimeException('Não foi possível confirmar o registro do envio. Nenhuma mensagem foi enviada.'); }
                try { ($this->sender)($emails, $package['bytes'], $package['filename'], $filters['month'], $filters['environment']); }
                catch (\Throwable $error) {
                    // A network failure may happen after SMTP accepted the data.
                    // Keep a durable inconclusive state; never retry automatically.
                    $this->update($id, $issuer, ['status' => 'uncertain', 'last_error' => 'O servidor de e-mail não confirmou o resultado. Confira o registro SMTP antes de reenviar.', 'updated_at' => gmdate('Y-m-d H:i:s')]);
                    throw new \RuntimeException('O envio ficou sem confirmação. Confira o servidor de e-mail do Perfex; o módulo não repetirá a tentativa automaticamente.', 0, $error);
                }
                $sentAt = gmdate('Y-m-d H:i:s');
                if (!$this->update($id, $issuer, ['status' => 'sent', 'sent_at' => $sentAt, 'last_error' => null, 'updated_at' => $sentAt])) {
                    throw new \RuntimeException('O servidor aceitou o e-mail, mas não foi possível confirmar o histórico. Confira o SMTP antes de reenviar.');
                }
                return ['id' => $id, 'status' => 'sent', 'already_sent' => false, 'message' => 'Pacote mensal aceito pelo servidor de e-mail para os destinatários selecionados.'];
            });
    }

    public function history(): array
    {
        $issuer = $this->repository->contextTaxId();
        $query = $this->model->db->select('id,month,environment,direction,format,status,created_at,sent_at,document_count,package_size,recipients_enc')
            ->where('issuer_tax_id', $issuer)->order_by('id', 'desc')->limit(100)->get($this->table());
        if (!$query) { throw new \RuntimeException('Não foi possível consultar o histórico de envio.'); }
        $rows = $query->result_array();
        foreach ($rows as &$row) {
            try {
                $recipients = json_decode($this->model->crypto()->decrypt($row['recipients_enc']), true, 16, JSON_THROW_ON_ERROR);
                $names = []; foreach ($recipients as $recipient) { $names[] = Config::text($recipient['name'] ?? '', 100); }
                $row['recipient_label'] = implode(', ', array_filter($names));
                if ($row['recipient_label'] === '') { $row['recipient_label'] = count($recipients) . ' destinatário(s)'; }
            } catch (\Throwable $error) { $row['recipient_label'] = 'Destinatários protegidos'; }
            unset($row['recipients_enc']);
        }
        unset($row); return $rows;
    }

    private function recipients(array $ids): array
    {
        if (!$ids || count($ids) !== count(array_unique($ids, SORT_REGULAR))) { throw new \InvalidArgumentException('Selecione os destinatários cadastrados, sem repetição.'); }
        foreach ($ids as $id) { if (!is_string($id) || !preg_match('/^[a-f0-9]{16}$/D', $id)) { throw new \InvalidArgumentException('Destinatário cadastrado inválido.'); } }
        $configured = $this->model->settings()['accounting_recipients'] ?? []; $selected = [];
        foreach ($configured as $recipient) {
            if (!is_array($recipient) || !in_array($recipient['id'] ?? '', $ids, true)) { continue; }
            $email = strtolower(trim(Config::text($recipient['email'] ?? '', 254)));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strpbrk($email, "\r\n") !== false) { throw new \InvalidArgumentException('Revise o e-mail do destinatário cadastrado.'); }
            $selected[] = ['id' => $recipient['id'], 'name' => Config::text($recipient['name'] ?? '', 100), 'email' => $email];
        }
        if (count($selected) !== count($ids)) { throw new \InvalidArgumentException('Um destinatário não está cadastrado nesta empresa. Confira a seleção.'); }
        $unique = []; foreach ($selected as $recipient) { $unique[$recipient['email']] = $recipient; }
        ksort($unique, SORT_STRING); return array_values($unique);
    }

    private function assertSend(): void
    {
        if (!nfse_nacional_can('national_view') || !nfse_nacional_can('accounting_send')) { throw new \RuntimeException('Você não tem permissão para enviar pacotes à contabilidade.', 403); }
    }

    private function table(): string
    {
        if ($this->prefix !== db_prefix()) { throw new \RuntimeException('A empresa ativa mudou. Atualize a página.', 403); }
        return $this->prefix . 'nfse_nacional_accounting_deliveries';
    }

    private function update(int $id, string $issuer, array $changes): bool
    {
        try { return $this->model->db->where('id', $id)->where('issuer_tax_id', $issuer)->update($this->table(), $changes) !== false; }
        catch (\Throwable $error) { return false; }
    }
}
