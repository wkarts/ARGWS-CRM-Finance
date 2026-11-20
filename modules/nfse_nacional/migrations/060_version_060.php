<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Version_060 extends App_module_migration
{
    public function up()
    {
        require_once __DIR__ . '/../helpers/nfse_nacional_helper.php';
        require_once __DIR__ . '/../autoload.php';
        nfse_nacional_scope();
        $CI = &get_instance(); $prefix = db_prefix(); $previousDebug = $CI->db->db_debug; $CI->db->db_debug = false;
        try {
            foreach (\NfseNacional\NationalRepository::schema() + \NfseNacional\MonthlyDelivery::schema() as $suffix => $definition) {
                $table = $prefix . 'nfse_nacional_' . $suffix;
                if ($CI->db->query("CREATE TABLE IF NOT EXISTS `{$table}` ({$definition}) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci") === false) {
                    throw new RuntimeException('Não foi possível criar o acervo nacional. Confira a permissão CREATE no banco da empresa.');
                }
            }
        } finally { $CI->db->db_debug = $previousDebug; }
    }
}
