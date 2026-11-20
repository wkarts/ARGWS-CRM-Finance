<?php

declare(strict_types=1);

namespace Multiempresa\Infrastructure;

final class Schema
{
    public static function uninstall(Database $db): void
    {
        // Exact module tables, in reverse dependency order. Native Perfex tables are never enumerated.
        foreach(['routes','user_preferences','lookup_cache','switch_tickets','user_organizations','organization_hosts','organization_config','execution_logs','plans','resources','certificates','profiles','onboardings','module_reviews','jobs','modules','update_steps','updates','gate','releases','settings','audit','events','endpoints','rate_limits','tokens','tickets','sessions','hosts','memberships','companies','org_members','users','organizations'] as $table){$db->exec('DROP TABLE IF EXISTS pme_'.$table);}
    }
    public static function install(Database $db, int $version = 120): void
    {
        $tables = [
            'organizations' => 'id VARCHAR(32) PRIMARY KEY, slug VARCHAR(63) NOT NULL UNIQUE, name VARCHAR(190) NOT NULL, active INTEGER NOT NULL DEFAULT 1',
            'users' => 'id VARCHAR(32) PRIMARY KEY, email VARCHAR(190) NOT NULL UNIQUE, name VARCHAR(190) NOT NULL, password_hash VARCHAR(255) NOT NULL, native_staff_id BIGINT NULL UNIQUE, native_fingerprint VARCHAR(64) NULL, active INTEGER NOT NULL DEFAULT 1, platform_admin INTEGER NOT NULL DEFAULT 0, auth_version INTEGER NOT NULL DEFAULT 1, totp TEXT NULL, created_at BIGINT NOT NULL',
            'org_members' => 'organization_id VARCHAR(32) NOT NULL, user_id VARCHAR(32) NOT NULL, role VARCHAR(20) NOT NULL, PRIMARY KEY (organization_id,user_id), FOREIGN KEY (organization_id) REFERENCES pme_organizations(id), FOREIGN KEY (user_id) REFERENCES pme_users(id)',
            'companies' => 'id VARCHAR(32) PRIMARY KEY, organization_id VARCHAR(32) NOT NULL, slug VARCHAR(63) NOT NULL, name VARCHAR(190) NOT NULL, mode VARCHAR(16) NOT NULL, table_prefix VARCHAR(48) NOT NULL, storage_identity VARCHAR(64) NOT NULL, secrets TEXT NOT NULL, status VARCHAR(24) NOT NULL, schema_version INTEGER NOT NULL DEFAULT 0, error_code VARCHAR(64) NULL, created_at BIGINT NOT NULL, UNIQUE (organization_id,slug), UNIQUE(storage_identity,table_prefix), FOREIGN KEY (organization_id) REFERENCES pme_organizations(id)',
            'memberships' => 'company_id VARCHAR(32) NOT NULL, user_id VARCHAR(32) NOT NULL, staff_id BIGINT NOT NULL, active INTEGER NOT NULL DEFAULT 1, PRIMARY KEY(company_id,user_id), UNIQUE(company_id,staff_id), FOREIGN KEY(company_id) REFERENCES pme_companies(id), FOREIGN KEY(user_id) REFERENCES pme_users(id)',
            'hosts' => 'host VARCHAR(190) PRIMARY KEY, company_id VARCHAR(32) NOT NULL, verified INTEGER NOT NULL DEFAULT 0, FOREIGN KEY(company_id) REFERENCES pme_companies(id)',
            'sessions' => 'token_hash VARCHAR(64) PRIMARY KEY, user_id VARCHAR(32) NOT NULL, company_id VARCHAR(32) NULL, auth_version INTEGER NOT NULL, csrf VARCHAR(64) NOT NULL, mfa INTEGER NOT NULL DEFAULT 0, expires_at BIGINT NOT NULL, FOREIGN KEY(user_id) REFERENCES pme_users(id)',
            'tickets' => 'token_hash VARCHAR(64) PRIMARY KEY, user_id VARCHAR(32) NOT NULL, company_id VARCHAR(32) NOT NULL, challenge VARCHAR(64) NOT NULL, auth_version INTEGER NOT NULL, mfa INTEGER NOT NULL, expires_at BIGINT NOT NULL, consumed INTEGER NOT NULL DEFAULT 0',
            'tokens' => 'id VARCHAR(32) PRIMARY KEY, token_hash VARCHAR(64) NOT NULL UNIQUE, company_id VARCHAR(32) NOT NULL, user_id VARCHAR(32) NOT NULL, name VARCHAR(190) NOT NULL, scopes TEXT NOT NULL, mfa INTEGER NOT NULL DEFAULT 0, expires_at BIGINT NOT NULL, revoked INTEGER NOT NULL DEFAULT 0, created_at BIGINT NOT NULL, FOREIGN KEY(company_id) REFERENCES pme_companies(id), FOREIGN KEY(user_id) REFERENCES pme_users(id)',
            'rate_limits' => 'bucket VARCHAR(64) PRIMARY KEY, count INTEGER NOT NULL, expires_at BIGINT NOT NULL',
            'endpoints' => 'id VARCHAR(32) PRIMARY KEY, company_id VARCHAR(32) NOT NULL, provider VARCHAR(40) NOT NULL, secret TEXT NOT NULL, token_hash VARCHAR(64) NOT NULL UNIQUE, processor VARCHAR(64) NOT NULL, active INTEGER NOT NULL DEFAULT 1, created_at BIGINT NOT NULL, FOREIGN KEY(company_id) REFERENCES pme_companies(id)',
            'events' => 'id VARCHAR(32) PRIMARY KEY, endpoint_id VARCHAR(32) NOT NULL, company_id VARCHAR(32) NOT NULL, provider_event VARCHAR(190) NOT NULL, digest VARCHAR(64) NOT NULL, payload LONGTEXT NOT NULL, status VARCHAR(20) NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, available_at BIGINT NOT NULL, lease VARCHAR(32) NULL, lease_until BIGINT NULL, error_code VARCHAR(64) NULL, created_at BIGINT NOT NULL, completed_at BIGINT NULL, UNIQUE(endpoint_id,provider_event), FOREIGN KEY(endpoint_id) REFERENCES pme_endpoints(id)',
            'audit' => 'id VARCHAR(32) PRIMARY KEY, actor_id VARCHAR(32) NULL, company_id VARCHAR(32) NULL, action VARCHAR(80) NOT NULL, details TEXT NOT NULL, created_at BIGINT NOT NULL, signature VARCHAR(64) NOT NULL',
            'settings' => 'name VARCHAR(80) PRIMARY KEY, value LONGTEXT NOT NULL',
            'releases' => 'id VARCHAR(32) PRIMARY KEY, root TEXT NOT NULL, version INTEGER NOT NULL, fingerprint VARCHAR(64) NOT NULL, created_at BIGINT NOT NULL',
            'gate' => 'id INTEGER PRIMARY KEY, state VARCHAR(20) NOT NULL, release_id VARCHAR(32) NULL',
            'updates' => 'id VARCHAR(32) PRIMARY KEY, source_release VARCHAR(32) NOT NULL, target_release VARCHAR(32) NOT NULL, status VARCHAR(24) NOT NULL, created_at BIGINT NOT NULL, actor_id VARCHAR(32) NOT NULL',
            'update_steps' => 'update_id VARCHAR(32) NOT NULL, company_id VARCHAR(32) NOT NULL, status VARCHAR(24) NOT NULL, backup TEXT NULL, checksum VARCHAR(64) NULL, attempts INTEGER NOT NULL DEFAULT 0, error_code VARCHAR(64) NULL, PRIMARY KEY(update_id,company_id)',
            'modules' => 'company_id VARCHAR(32) NOT NULL, name VARCHAR(80) NOT NULL, fingerprint VARCHAR(64) NOT NULL, active INTEGER NOT NULL DEFAULT 0, approved_by VARCHAR(32) NOT NULL, PRIMARY KEY(company_id,name)',
            'jobs' => 'id VARCHAR(32) PRIMARY KEY, type VARCHAR(24) NOT NULL, target VARCHAR(32) NOT NULL, actor_id VARCHAR(32) NOT NULL, status VARCHAR(24) NOT NULL, started_at BIGINT NULL, finished_at BIGINT NULL, result TEXT NULL, error_code VARCHAR(64) NULL, created_at BIGINT NOT NULL',
            'module_reviews' => 'release_id VARCHAR(32) NOT NULL, company_id VARCHAR(32) NOT NULL, name VARCHAR(80) NOT NULL, fingerprint VARCHAR(64) NOT NULL, PRIMARY KEY(release_id,company_id,name)',
            'onboardings' => 'id VARCHAR(32) PRIMARY KEY, request_key VARCHAR(64) NOT NULL UNIQUE, actor_id VARCHAR(32) NOT NULL, organization_id VARCHAR(32) NOT NULL, company_id VARCHAR(32) NOT NULL UNIQUE, digest VARCHAR(64) NOT NULL, payload LONGTEXT NOT NULL, steps LONGTEXT NOT NULL, status VARCHAR(24) NOT NULL, step_index INTEGER NOT NULL DEFAULT 0, error_code VARCHAR(64) NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL',
            'profiles' => 'company_id VARCHAR(32) PRIMARY KEY, data LONGTEXT NOT NULL, updated_at BIGINT NOT NULL',
            'certificates' => 'company_id VARCHAR(32) PRIMARY KEY, secret LONGTEXT NOT NULL, metadata TEXT NOT NULL, updated_at BIGINT NOT NULL, actor_id VARCHAR(32) NOT NULL',
            'resources' => 'id VARCHAR(32) PRIMARY KEY, organization_id VARCHAR(32) NOT NULL, company_id VARCHAR(32) NULL, kind VARCHAR(32) NOT NULL, resource_key VARCHAR(190) NOT NULL UNIQUE, data LONGTEXT NOT NULL, status VARCHAR(24) NOT NULL, updated_at BIGINT NOT NULL',
            'plans' => 'id VARCHAR(32) PRIMARY KEY, actor_id VARCHAR(32) NOT NULL, organization_id VARCHAR(32) NOT NULL, company_id VARCHAR(32) NULL, operation VARCHAR(32) NOT NULL, digest VARCHAR(64) NOT NULL, payload LONGTEXT NOT NULL, impact LONGTEXT NOT NULL, status VARCHAR(24) NOT NULL, expires_at BIGINT NOT NULL, created_at BIGINT NOT NULL',
            'execution_logs' => 'id VARCHAR(32) PRIMARY KEY, organization_id VARCHAR(32) NULL, company_id VARCHAR(32) NULL, actor_id VARCHAR(32) NULL, execution_id VARCHAR(32) NULL, stage VARCHAR(80) NOT NULL, status VARCHAR(24) NOT NULL, duration_ms BIGINT NOT NULL DEFAULT 0, details LONGTEXT NOT NULL, created_at BIGINT NOT NULL, signature VARCHAR(64) NOT NULL',
            'organization_config' => 'organization_id VARCHAR(32) PRIMARY KEY, data TEXT NOT NULL, status VARCHAR(24) NOT NULL DEFAULT \'draft\', updated_at BIGINT NOT NULL',
            'organization_hosts' => 'host VARCHAR(190) PRIMARY KEY, organization_id VARCHAR(32) NOT NULL, verified INTEGER NOT NULL DEFAULT 0',
            'user_organizations' => 'user_id VARCHAR(32) PRIMARY KEY, organization_id VARCHAR(32) NOT NULL',
            'switch_tickets' => 'token_hash VARCHAR(64) PRIMARY KEY, user_id VARCHAR(32) NOT NULL, organization_id VARCHAR(32) NOT NULL, source_company VARCHAR(32) NOT NULL, target_company VARCHAR(32) NOT NULL, auth_version INTEGER NOT NULL, mfa INTEGER NOT NULL, expires_at BIGINT NOT NULL, consumed INTEGER NOT NULL DEFAULT 0',
        ];
        $tables['routes']='route_key VARCHAR(20) PRIMARY KEY, target_id VARCHAR(32) NOT NULL UNIQUE';
        $tables['user_preferences']='user_id VARCHAR(32) PRIMARY KEY, principal_access INTEGER NULL, last_company_id VARCHAR(32) NULL, identity_company VARCHAR(32) NULL, disabled_on_uninstall INTEGER NOT NULL DEFAULT 0';
        $tables['lookup_cache']='cache_key VARCHAR(64) PRIMARY KEY, data TEXT NOT NULL, expires_at BIGINT NOT NULL';
        $stages = [100=>['organizations','users','org_members','companies','memberships'],
            101=>['hosts','sessions','tickets','tokens','rate_limits','endpoints','events','audit','settings'],
            102=>['releases','gate','updates','update_steps','modules','jobs','module_reviews'],104=>['onboardings'],
            105=>['profiles','certificates'],106=>['resources','plans'],107=>['execution_logs'],108=>['organization_config','organization_hosts'],109=>['user_organizations','switch_tickets'],112=>['lookup_cache'],120=>['routes','user_preferences']];
        $selected=[];
        foreach ($stages as $stage=>$names) { if ($stage <= $version) { $selected=array_merge($selected,$names); } }
        foreach (array_intersect_key($tables,array_flip($selected)) as $name => $columns) {
            $suffix = $db->driver() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
            $db->exec('CREATE TABLE IF NOT EXISTS pme_' . $name . ' (' . $columns . ')' . $suffix);
        }
        if($version>=112){
            if($db->driver()==='mysql'&&strtolower((string)($db->one("SHOW COLUMNS FROM pme_settings WHERE Field='value'")['Type']??''))!=='longtext'){$db->exec('ALTER TABLE pme_settings MODIFY value LONGTEXT NOT NULL');}
            try{$db->exec('CREATE INDEX pme_lookup_expiry ON pme_lookup_cache(expires_at)');}
            catch(\PDOException $e){if(!str_contains(strtolower($e->getMessage()),'already exists')&&!str_contains($e->getMessage(),'Duplicate key name')){throw $e;}}
        }
        if ($version < 101) { return; }
        foreach (['events_ready' => ['events','status,available_at'], 'events_tenant' => ['events','company_id,status'], 'sessions_expiry' => ['sessions','expires_at'], 'audit_tenant' => ['audit','company_id,created_at']] as $name => [$table,$fields]) {
            try {
                $db->exec("CREATE INDEX pme_{$name} ON pme_{$table} ({$fields})");
            } catch (\PDOException $e) {
                if (!str_contains(strtolower($e->getMessage()), 'already exists') && !str_contains($e->getMessage(), 'Duplicate key name')) {
                    throw $e;
                }
            }
        }
        if ($version >= 102 && !$db->one('SELECT id FROM pme_gate WHERE id=1')) {
            $db->exec("INSERT INTO pme_gate(id,state) VALUES(1,'setup')");
        }
        if ($version >= 107) {
            foreach (['logs_scope'=>'organization_id,company_id,created_at','logs_execution'=>'execution_id,created_at','logs_time'=>'created_at,id','logs_status'=>'status,created_at'] as $index=>$fields) {
                try {$db->exec('CREATE INDEX pme_'.$index.' ON pme_execution_logs ('.$fields.')');}
                catch (\PDOException $e) {if (!str_contains(strtolower($e->getMessage()),'already exists') && !str_contains($e->getMessage(),'Duplicate key name')) {throw $e;}}
            }
        }
    }
}
