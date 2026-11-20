<?php
defined('BASEPATH') or exit('No direct script access allowed');
class Migration_Version_081 extends App_module_migration
{
    public function up()
    {
        require_once __DIR__ . '/../autoload.php';
        nfse_nacional_scope();
        $ci = &get_instance();
        foreach (\NfseNacional\Automation::schema() as $suffix => $definition) {
            $table = db_prefix() . 'nfse_nacional_' . $suffix;
            if ($ci->db->query("CREATE TABLE IF NOT EXISTS `{$table}` ({$definition}) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci") === false) {
                throw new RuntimeException('Não foi possível criar a estrutura de alíquotas e automação.');
            }
        }
    }
}
