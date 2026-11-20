<?php
defined('BASEPATH') or exit('No direct script access allowed');
class Migration_Version_087 extends App_module_migration
{
    public function up()
    {
        require_once __DIR__ . '/../autoload.php';
        nfse_nacional_scope(); $ci = &get_instance();
        $table = db_prefix() . 'nfse_nacional_sync_runs';
        if (!$ci->db->table_exists($table)) { throw new RuntimeException('Conclua as migrações anteriores da sincronização antes de atualizar.'); }
        if ($ci->db->field_exists('last_worker_at', $table)) { return; }
        $debug = $ci->db->db_debug; $ci->db->db_debug = false;
        try {
            if ($ci->db->query("ALTER TABLE `{$table}` ADD COLUMN `last_worker_at` DATETIME NULL") === false) {
                throw new RuntimeException('Não foi possível atualizar o acompanhamento da sincronização. Execute novamente a atualização.');
            }
        } finally { $ci->db->db_debug = $debug; }
    }
}
