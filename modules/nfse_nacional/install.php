<?php

defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/helpers/nfse_nacional_helper.php';
require_once __DIR__ . '/autoload.php';
$nfseMissing = nfse_nacional_requirements();
if ($nfseMissing !== []) {
    throw new RuntimeException(implode(' ', $nfseMissing));
}
nfse_nacional_scope();
$CI = &get_instance();
$nfsePrefix = db_prefix();
$nfseTables = [
    'settings' => "`id` TINYINT UNSIGNED NOT NULL,
        `settings_json` LONGTEXT NOT NULL,
        `certificate_enc` LONGTEXT NULL,
        `certificate_meta` TEXT NULL,
        `auth_material_enc` LONGTEXT NULL,
        `updated_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`)",
    'profiles' => "`client_id` INT NOT NULL,
        `profile_json` LONGTEXT NOT NULL,
        `updated_at` DATETIME NOT NULL,
        PRIMARY KEY (`client_id`)",
    'recipients' => "`id` INT NOT NULL AUTO_INCREMENT,
        `client_id` INT NOT NULL,
        `label` VARCHAR(100) NOT NULL,
        `data_json` LONGTEXT NOT NULL,
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`), KEY `client_id` (`client_id`)",
    'invoice_preferences' => "`invoice_id` INT NOT NULL,
        `client_id` INT NOT NULL,
        `recipient_selection` VARCHAR(64) NOT NULL,
        `updated_by` INT NOT NULL,
        `updated_at` DATETIME NOT NULL,
        PRIMARY KEY (`invoice_id`), KEY `client_recipient` (`client_id`,`recipient_selection`)",
    'documents' => "`id` BIGINT NOT NULL AUTO_INCREMENT,
        `invoice_id` INT NOT NULL,
        `client_id` INT NOT NULL,
        `environment` CHAR(1) NOT NULL,
        `revision` INT UNSIGNED NOT NULL DEFAULT 0,
        `replacement_of_id` BIGINT NULL,
        `status` VARCHAR(24) NOT NULL DEFAULT 'draft',
        `draft_json` LONGTEXT NOT NULL,
        `draft_hash` CHAR(64) NOT NULL,
        `invoice_fingerprint` CHAR(64) NOT NULL,
        `dps_id` VARCHAR(50) NULL,
        `dps_series` VARCHAR(5) NULL,
        `dps_number` VARCHAR(15) NULL,
        `request_enc` LONGTEXT NULL,
        `response_enc` LONGTEXT NULL,
        `fiscal_meta_json` LONGTEXT NULL,
        `cancellation_event_enc` LONGTEXT NULL,
        `access_key` VARCHAR(50) NULL,
        `nfse_number` VARCHAR(20) NULL,
        `service_amount` DECIMAL(15,2) NOT NULL,
        `asaas_payment_id` VARCHAR(100) NULL,
        `send_after_authorized` TINYINT NOT NULL DEFAULT 0,
        `recipient_email` VARCHAR(191) NULL,
        `email_status` VARCHAR(20) NOT NULL DEFAULT 'pending',
        `email_sent_at` DATETIME NULL,
        `last_error` TEXT NULL,
        `attempts` INT NOT NULL DEFAULT 0,
        `authorized_at` DATETIME NULL,
        `created_by` INT NOT NULL,
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `invoice_environment_revision` (`invoice_id`,`environment`,`revision`),
        UNIQUE KEY `replacement_of_id` (`replacement_of_id`),
        UNIQUE KEY `environment_dps` (`environment`,`dps_id`),
        UNIQUE KEY `environment_access_key` (`environment`,`access_key`),
        KEY `client_created` (`client_id`,`created_at`),
        KEY `status_updated` (`status`,`updated_at`)",
    'sequences' => "`scope` VARCHAR(64) NOT NULL,
        `last_number` BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (`scope`)",
    'events' => "`id` BIGINT NOT NULL AUTO_INCREMENT,
        `document_id` BIGINT NOT NULL,
        `event_type` VARCHAR(40) NOT NULL,
        `message` TEXT NOT NULL,
        `staff_id` INT NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`), KEY `document_id` (`document_id`)",
];
$nfseTables += \NfseNacional\NationalRepository::schema();
$nfseTables += \NfseNacional\FiscalRequestRepository::schema();
$nfseTables += \NfseNacional\MonthlyDelivery::schema();
$nfseTables += \NfseNacional\Automation::schema();
$nfseTables += \NfseNacional\SyncQueue::schema();

$nfseOriginalDebug = $CI->db->db_debug;
$CI->db->db_debug = false;
try {
    foreach ($nfseTables as $nfseSuffix => $nfseDefinition) {
        $nfseTable = $nfsePrefix . 'nfse_nacional_' . $nfseSuffix;
        if ($CI->db->query("CREATE TABLE IF NOT EXISTS `{$nfseTable}` ({$nfseDefinition}) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci") === false) {
            throw new RuntimeException('Não foi possível criar as tabelas de NFS-e Nacional. Verifique a permissão CREATE do usuário de banco da empresa e reative o módulo.');
        }
    }
    $nfseSettings = $nfsePrefix . 'nfse_nacional_settings';
    if ($CI->db->query("INSERT IGNORE INTO `{$nfseSettings}` (`id`,`settings_json`,`updated_at`) VALUES (1, ?, ?)", ['{}', date('Y-m-d H:i:s')]) === false) {
        throw new RuntimeException('Não foi possível iniciar as configurações de NFS-e Nacional. Verifique a permissão de escrita no banco.');
    }
} finally {
    $CI->db->db_debug = $nfseOriginalDebug;
}
unset($nfseMissing, $nfsePrefix, $nfseTables, $nfseOriginalDebug, $nfseSuffix, $nfseDefinition, $nfseTable, $nfseSettings);
