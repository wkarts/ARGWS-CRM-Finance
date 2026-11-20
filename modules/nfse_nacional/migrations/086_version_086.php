<?php
defined('BASEPATH') or exit('No direct script access allowed');
class Migration_Version_086 extends App_module_migration
{
    public function up()
    {
        require_once __DIR__ . '/../autoload.php';
        nfse_nacional_scope();
        $ci = &get_instance();
        $table = db_prefix() . 'nfse_nacional_competence_iss_rates';
        $definition = \NfseNacional\Automation::schema()['competence_iss_rates'];
        $debug = $ci->db->db_debug;
        $ci->db->db_debug = false;
        try {
            if ($ci->db->query("CREATE TABLE IF NOT EXISTS `{$table}` ({$definition}) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci") === false) {
                throw new RuntimeException('Não foi possível criar o cadastro de alíquotas do ISSQN por competência. Execute novamente a atualização.');
            }
        } finally { $ci->db->db_debug = $debug; }
    }
}
