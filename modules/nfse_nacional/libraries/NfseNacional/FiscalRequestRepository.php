<?php
declare(strict_types=1);

namespace NfseNacional;

/** Durable event requests, separated from diagnostic logs and from immutable NFS-e XML. */
final class FiscalRequestRepository
{
    private $model;
    private $db;
    private string $scope;
    private string $prefix;
    public function __construct($model)
    {
        $this->model = $model; $this->db = $model->db; $this->scope = nfse_nacional_scope(); $this->prefix = db_prefix();
        if (!preg_match('/^[A-Za-z0-9_]{0,40}$/D', $this->prefix)) { throw new \RuntimeException('Empresa indisponível.'); }
        $this->db->save_queries = false; $this->db->db_debug = false;
    }
    public static function schema(): array
    {
        return ['fiscal_requests' => "`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `issuer_tax_id` VARCHAR(14) NOT NULL, `environment` CHAR(1) NOT NULL, `access_key` VARCHAR(50) NOT NULL,
            `event_type` CHAR(6) NOT NULL, `status` VARCHAR(16) NOT NULL,
            `request_enc` LONGTEXT NULL, `request_hash` CHAR(64) NULL, `response_enc` LONGTEXT NULL,
            `event_id` VARCHAR(80) NULL, `processed_at` VARCHAR(40) NULL, `record_number` VARCHAR(30) NULL,
            `attempts` INT UNSIGNED NOT NULL DEFAULT 0, `last_error` VARCHAR(1000) NULL,
            `created_by` INT NOT NULL, `created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `issuer_environment_event` (`issuer_tax_id`,`environment`,`access_key`,`event_type`)"];
    }
    public function find(string $issuer, string $environment, string $key, string $type): ?array
    {
        $this->scope($issuer);
        $query = $this->db->where('issuer_tax_id', $issuer)->where('environment', $environment)->where('access_key', $key)
            ->where('event_type', $type)->get($this->table());
        if (!$query) { throw new \RuntimeException('O histórico de eventos está indisponível. Atualize o banco de dados do módulo.'); }
        return $query->row_array() ?: null;
    }
    public function save(string $issuer, string $environment, string $key, string $type, array $changes): array
    {
        $old = $this->find($issuer, $environment, $key, $type);
        $changes['updated_at'] = gmdate('Y-m-d H:i:s');
        if ($old) {
            $ok = $this->db->where('id', $old['id'])->where('issuer_tax_id', $issuer)->update($this->table(), $changes);
        } else {
            $ok = $this->db->insert($this->table(), $changes + ['issuer_tax_id' => $issuer, 'environment' => $environment,
                'access_key' => $key, 'event_type' => $type, 'attempts' => 0, 'created_by' => (int) get_staff_user_id(),
                'created_at' => gmdate('Y-m-d H:i:s')]);
        }
        if ($ok === false) { throw new \RuntimeException('Não foi possível persistir o pedido fiscal. Consulte seu resultado antes de tentar novamente.'); }
        return $this->find($issuer, $environment, $key, $type);
    }
    private function scope(string $issuer): void
    {
        if ($this->prefix !== db_prefix() || !hash_equals($this->scope, nfse_nacional_scope()) || !nfse_nacional_can('view')) {
            throw new \RuntimeException('O contexto da empresa mudou. Atualize a página.');
        }
        $current = Config::taxId((string) ($this->model->settings()['provider']['tax_id'] ?? ''));
        if ($current === '' || !hash_equals($current, $issuer) || !Validator::taxId($issuer)) { throw new \RuntimeException('O emitente do evento não corresponde à empresa atual.'); }
        CompanyContext::current()->assertIssuer($issuer);
    }
    private function table(): string { return $this->prefix . 'nfse_nacional_fiscal_requests'; }
}
