<?php

declare(strict_types=1);

namespace Multiempresa\Perfex;

use Multiempresa\Domain\Id;

final class TenantSchema
{
    public static function install(\PDO $pdo, string $p, string $company): void
    {
        Id::prefix($p);
        $suffix = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}pme_owner` (id INTEGER PRIMARY KEY,company_id VARCHAR(32) NOT NULL)".$suffix);
        $owner=$pdo->query("SELECT company_id FROM `{$p}pme_owner` WHERE id=1")->fetchColumn();
        if($owner && $owner!==$company){throw new \Multiempresa\Domain\Failure('storage_owner','Este prefixo pertence a outra empresa.',403);}
        if(!$owner){$stmt = $pdo->prepare("INSERT INTO `{$p}pme_owner` (id,company_id) VALUES(1,?)");$stmt->execute([$company]);}
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}pme_inbox` (endpoint_id VARCHAR(32) NOT NULL,event_id VARCHAR(190) NOT NULL,payload LONGTEXT NOT NULL,received_at DATETIME NOT NULL,PRIMARY KEY(endpoint_id,event_id))".$suffix);
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}pme_bootstrap` (table_name VARCHAR(64) PRIMARY KEY,template_hash VARCHAR(64) NOT NULL,seeded INTEGER NOT NULL DEFAULT 0)".$suffix);
    }
}
