<?php
defined('BASEPATH') or exit('No direct script access allowed');

/** Upgrade in place: fiscal archives, numbering and encrypted credentials are retained. */
class Migration_Version_020 extends App_module_migration
{
    public function up()
    {
        require dirname(__DIR__) . '/install.php';
        $ci = &get_instance();
        $table = db_prefix() . 'nfse_nacional_settings';
        $row = $ci->db->where('id', 1)->get($table)->row_array();
        $settings = json_decode((string) ($row['settings_json'] ?? '{}'), true, 64, JSON_THROW_ON_ERROR) ?: [];
        if (!array_key_exists('use_local_certificate', $settings)) {
            $settings['use_local_certificate'] = empty($row['certificate_enc']) ? '0' : '1';
            if (!$ci->db->where('id', 1)->update($table, ['settings_json' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)])) {
                throw new RuntimeException('Não foi possível concluir a atualização das configurações de NFS-e.');
            }
        }
    }
}
