<?php
declare(strict_types=1);
namespace NfseNacional;

/** Each step can be repeated after interrupted DDL/provisioning; no business rows are removed. */
final class Upgrade083
{
    public static function run($model): void
    {
        nfse_nacional_scope(); $db = $model->db;
        $model->lock('upgrade083', function () use ($model, $db) {
            foreach (SyncQueue::schema() as $suffix => $definition) {
                self::must($db->query('CREATE TABLE IF NOT EXISTS `' . db_prefix() . 'nfse_nacional_' . $suffix . '` (' . $definition . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'));
            }
            $sync = db_prefix() . 'nfse_nacional_national_sync';
            foreach (['processed_count', 'batch_count'] as $column) {
                if (!$db->field_exists($column, $sync)) { self::must($db->query('ALTER TABLE `' . $sync . '` ADD `' . $column . '` BIGINT NOT NULL DEFAULT 0')); }
            }
            $targets = $model->table('automation_targets');
            if (!$db->field_exists('trigger_event', $targets)) {
                self::must($db->query('ALTER TABLE `' . $targets . "` ADD trigger_event VARCHAR(12) NOT NULL DEFAULT ''"));
            }
            $old = $model->settings()['automation']['trigger'] ?? 'paid';
            if (!in_array($old, ['created', 'paid'], true)) { throw new \RuntimeException('Tipo anterior de automação inválido.'); }
            self::must($db->query('UPDATE `' . $targets . '` SET trigger_event=? WHERE trigger_event=\'\'', [$old]));
            $indexes = $db->query('SHOW INDEX FROM `' . $targets . '`')->result_array();
            $primary = array_values(array_filter($indexes, static fn($r) => $r['Key_name'] === 'PRIMARY'));
            if (count($primary) === 2) { self::must($db->query('ALTER TABLE `' . $targets . '` DROP PRIMARY KEY, ADD PRIMARY KEY(client_id,recipient_selection,trigger_event)')); }
            $jobs = $model->table('automation_jobs');
            $indexes = $db->query('SHOW INDEX FROM `' . $jobs . '`')->result_array();
            $names = array_column($indexes, 'Key_name');
            if (!in_array('invoice_environment_trigger', $names, true)) {
                self::must($db->query('ALTER TABLE `' . $jobs . '` ADD UNIQUE KEY invoice_environment_trigger(invoice_id,environment,trigger_event)'));
            }
            if (in_array('invoice_environment', $names, true)) { self::must($db->query('ALTER TABLE `' . $jobs . '` DROP INDEX invoice_environment')); }
            UnifiedAuth::upgrade($model);
            // Materialize the derived two-rule settings; legacy single-rule settings remain as a backup.
            $model->saveSettings($model->settings());
        });
    }
    private static function must($result): void
    {
        if ($result === false) { throw new \RuntimeException('Atualização 0.8.3 interrompida. Confira as permissões do banco e repita a atualização. As etapas concluídas e os dados foram preservados.'); }
    }
}
