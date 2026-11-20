<?php
defined('BASEPATH') or exit('No direct script access allowed');

/** Encrypted per-environment authentication material; no existing keys are migrated or deleted. */
class Migration_Version_078 extends App_module_migration
{
    public function up()
    {
        nfse_nacional_scope();
        $CI = &get_instance();
        $table = db_prefix() . 'nfse_nacional_settings';
        $previousDebug = $CI->db->db_debug;
        $CI->db->db_debug = false;
        try {
            if (!$CI->db->field_exists('auth_material_enc', $table)) {
                if ($CI->db->query("ALTER TABLE `{$table}` ADD COLUMN `auth_material_enc` LONGTEXT NULL AFTER `certificate_meta`") === false) {
                    throw new RuntimeException('Não foi possível incluir o armazenamento protegido de credenciais por ambiente.');
                }
            }
        } finally {
            $CI->db->db_debug = $previousDebug;
        }
    }
}
