<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Version_070 extends App_module_migration
{
    public function up()
    {
        require_once __DIR__ . '/../helpers/nfse_nacional_helper.php';
        require_once __DIR__ . '/../autoload.php';
        nfse_nacional_scope();
        $CI = &get_instance(); $table = db_prefix() . 'nfse_nacional_documents';
        foreach (['revision' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'replacement_of_id' => 'BIGINT NULL'] as $column => $definition) {
            if (!$CI->db->field_exists($column, $table) && $CI->db->query("ALTER TABLE `{$table}` ADD `{$column}` {$definition}") === false) {
                throw new RuntimeException('Não foi possível preparar o histórico de substituições. Confira a permissão ALTER no banco.');
            }
        }
        $query = $CI->db->query("SHOW INDEX FROM `{$table}`");
        if (!$query) { throw new RuntimeException('Não foi possível conferir os índices fiscais.'); }
        $indexes = array_column($query->result_array(), 'Key_name');
        // Add the replacement uniqueness before removing the old one, including on interrupted upgrades.
        if (!in_array('invoice_environment_revision', $indexes, true)
            && $CI->db->query("ALTER TABLE `{$table}` ADD UNIQUE KEY `invoice_environment_revision` (`invoice_id`,`environment`,`revision`)") === false) {
            throw new RuntimeException('Não foi possível criar o índice de versões da NFS-e.');
        }
        if (!in_array('replacement_of_id', $indexes, true)
            && $CI->db->query("ALTER TABLE `{$table}` ADD UNIQUE KEY `replacement_of_id` (`replacement_of_id`)") === false) {
            throw new RuntimeException('Não foi possível proteger o vínculo de substituição da NFS-e.');
        }
        if (in_array('invoice_environment', $indexes, true)
            && $CI->db->query("ALTER TABLE `{$table}` DROP INDEX `invoice_environment`") === false) {
            throw new RuntimeException('Não foi possível concluir o índice de substituições.');
        }
        foreach (\NfseNacional\FiscalRequestRepository::schema() as $suffix => $definition) {
            $requestTable = db_prefix() . 'nfse_nacional_' . $suffix;
            if ($CI->db->query("CREATE TABLE IF NOT EXISTS `{$requestTable}` ({$definition}) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci") === false) {
                throw new RuntimeException('Não foi possível preparar o histórico de pedidos fiscais. Confira a permissão CREATE no banco.');
            }
        }
    }
}
