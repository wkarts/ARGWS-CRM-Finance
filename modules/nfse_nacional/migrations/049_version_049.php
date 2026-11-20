<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Version_049 extends App_module_migration
{
    public function up()
    {
        require_once __DIR__ . '/../helpers/nfse_nacional_helper.php';
        nfse_nacional_scope();
        $CI = &get_instance();
        $table = db_prefix() . 'nfse_nacional_documents';
        foreach (['fiscal_meta_json', 'cancellation_event_enc'] as $column) {
            if (!$CI->db->field_exists($column, $table)
                && $CI->db->query("ALTER TABLE `{$table}` ADD `{$column}` LONGTEXT NULL") === false) {
                throw new RuntimeException('Não foi possível atualizar os registros de situação fiscal. Confira a permissão ALTER no banco da empresa.');
            }
        }
    }
}
