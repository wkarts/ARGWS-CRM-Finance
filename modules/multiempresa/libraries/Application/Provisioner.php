<?php

declare(strict_types=1);

namespace Multiempresa\Application;

use Multiempresa\Domain\{Id,Failure};
use Multiempresa\Infrastructure\{MySqlStorage,SqlScript,Files};
use Multiempresa\Perfex\Adapter;

final class Provisioner
{
    public function __construct(private App $app)
    {
    }
    public function create(array $actor, array $input, bool $adopt = false, ?string $companyId = null, bool $activate = true): string
    {
        $currentRelease=(new CoreMaintenance($this->app))->synchronize();
        $controlLock = new \Multiempresa\Infrastructure\AdvisoryLock($this->app, 'updates');
        $gate = $this->app->db->one('SELECT state FROM pme_gate WHERE id=1');
        if (!$gate || $gate['state'] !== 'active') {
            throw new Failure('maintenance', 'Cadastros bloqueados durante manutenção global.', 503);
        }
        $org = (string)($input['organization_id'] ?? '');
        $this->app->catalog->assertManager($actor, $org);
        $name = Catalog::name($input['name'] ?? '');
        $slug = Id::slug($input['slug'] ?? '');
        $existing=$companyId?$this->app->db->one('SELECT * FROM pme_companies WHERE id=?',[$companyId]):null;
        if($companyId&&!$existing){throw new Failure('company_not_found','O cadastro desta empresa não existe mais.',404);}
        $resume=$existing && !empty($input['resume_authorized']) && (int)$existing['schema_version']===0 && in_array($existing['status'],['draft','failed','provisioning','suspended'],true);
        if(($existing&&!$resume)||$this->app->db->one('SELECT id FROM pme_companies WHERE organization_id=? AND slug=? AND id<>?',[$org,$slug,$companyId??''])){throw new Failure('already_provisioned','Utilize a revisão de impacto para retomar ou reprovisionar esta empresa.',409);}
        if($existing && ($existing['organization_id']!==$org||$existing['slug']!==$slug)){throw new Failure('company_scope','Cadastro divergente da execução autorizada.',403);}
        if (!$this->app->db->one('SELECT id FROM pme_organizations WHERE id=? AND active=1',[$org])) {
            throw new Failure('organization_inactive','Selecione uma organização ativa.');
        }
        $mode = $input['mode'] ?? 'database';
        $driver = $this->app->storage($mode);
        $id = $companyId ?? Id::new();
        if (!preg_match('/\A[a-f0-9]{32}\z/',$id)) { throw new Failure('company_id','Identificador de empresa inválido.'); }
        // Existing/adopted installations keep their physical names; new exclusive schemas have no prefix.
        $prefix = Id::prefix((string)($existing['table_prefix'] ?? ($mode === 'database' && !$adopt ? '' : ($input['prefix'] ?? ($mode === 'database' ? '' : 'e'.substr($id, 0, 12).'_')))), $mode);
        $c = $input['connection'];
        $driver->validate($c, $prefix);
        $pdo = $driver->connect($c);
        foreach ($pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $existingTable) {
            if (str_ends_with($existingTable,'pme_owner')) {
                $existingPrefix=substr($existingTable,0,-strlen('pme_owner'));
                if ($resume && $existingPrefix===$prefix) { $owner=$pdo->query('SELECT company_id FROM `'.$existingTable.'` WHERE id=1')->fetchColumn(); if($owner===$id){continue;} }
                if (str_starts_with($prefix,$existingPrefix) || str_starts_with($existingPrefix,$prefix)) { throw new Failure('storage_owned','O banco contém um prefixo empresarial reservado, inclusive por outro endereço.'); }
            }
        }
        $identity = MySqlStorage::identity($c);
        foreach ($this->app->db->all('SELECT table_prefix,mode FROM pme_companies WHERE storage_identity=? AND id<>?', [$identity,$id]) as $other) {
            if ($mode === 'database' || $other['mode'] === 'database' || str_starts_with($other['table_prefix'], $prefix) || str_starts_with($prefix, $other['table_prefix'])) {
                throw new Failure('storage_overlap', 'Banco ou prefixo já reservado por outra empresa.');
            }
        }
        $tables = $driver->tables($pdo, $prefix);
        if (!$resume && in_array($prefix.'pme_owner', $tables, true)) {
            throw new Failure('storage_owned', 'Este conjunto de tabelas já pertence a uma empresa, mesmo quando acessado por outro endereço de banco.');
        }
        if (!$adopt && $tables && !$resume) {
            throw new Failure('storage_not_empty', 'O prefixo de destino precisa estar vazio.');
        }
        if ($adopt && !$tables) {
            throw new Failure('adopt_empty', 'O banco existente não contém tabelas deste prefixo.');
        }
        $key = $existing ? $this->app->vault->open($existing['secrets'])['encryption_key'] : ($adopt ? ($input['encryption_key'] ?? '') : bin2hex(random_bytes(16)));
        if($resume && !$adopt && $tables && !in_array($prefix.'pme_owner',$tables,true)){throw new Failure('legacy_partial_schema','Há tabelas de uma execução antiga sem registro de propriedade. Revise o destino no plano de recuperação antes de continuar.');}
        if (strlen($key) !== 32) {
            throw new Failure('encryption_key', 'Informe a chave APP_ENC_KEY original, com 32 caracteres.');
        }
        if($adopt){$this->app->catalog->assertUserOrganization($actor['id'],$org);}
        $administrator=$adopt?null:($input['administrator']??(new InitialAdministrator($this->app))->normalize($actor,$input,false,$id));
        $secrets = $this->app->vault->seal(['connection' => $input['runtime_connection']??$c,'encryption_key' => $key]);
        // Reservation persists before DDL; failures leave a visible, blocked company for recovery.
        if(!$existing){$this->app->db->exec('INSERT INTO pme_companies(id,organization_id,slug,name,mode,table_prefix,storage_identity,secrets,status,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)', [$id,$org,$slug,$name,$mode,$prefix,$identity,$secrets,'provisioning',time()]);}else{$this->app->db->exec("UPDATE pme_companies SET status='provisioning',error_code=NULL,storage_identity=?,secrets=? WHERE id=?",[$identity,$secrets,$id]);}
        (new CompanyRoutes($this->app))->key($id);
        try {
            if (!$adopt) {
                $release = $this->app->db->one('SELECT r.* FROM pme_releases r JOIN pme_gate g ON r.id=g.release_id WHERE g.id=1');
                if (!$release) { throw new Failure('schema_version','Versão publicada não encontrada.'); }
                \Multiempresa\Perfex\TenantSchema::install($pdo,$prefix,$id);
                \Multiempresa\Perfex\SchemaTemplate::install($this->app,$pdo,$prefix,$release,$id);
                $p = $prefix;
                $bindings=(new InitialAdministrator($this->app))->install($actor,$this->app->catalog->company($id),$pdo,$administrator);
                $stmt = $pdo->prepare("UPDATE `{$p}options` SET value=? WHERE name=?");
                foreach (['companyname' => $name,'default_timezone' => 'America/Bahia','active_language' => 'portuguese_br'] as $option => $value) {
                    $stmt->execute([$value,$option]);
                }
            } else {
                $staffId = (int)($input['staff_id'] ?? 0);
                $stmt = $pdo->prepare('SELECT email,active,admin FROM `'.$prefix.'staff` WHERE staffid=?');
                $stmt->execute([$staffId]);
                $staff = $stmt->fetch();
                if (!$staff || !(int)$staff['admin'] || !(int)$staff['active'] || strtolower($staff['email']) !== $actor['email']) {
                    throw new Failure('adopt_staff', 'Associe o administrador local ativo ao mesmo e-mail global.');
                }
                $bindings=[[$actor['id'],$staffId]]; // Adoption explicitly selects and validates this existing local administrator.
                if (!empty($input['uploads_dir'])) {
                    $this->copyUploads($input['uploads_dir'], $this->app->state('companies/'.$id.'/uploads'));
                }
                if (!empty($input['media_dir'])) {
                    $this->copyUploads($input['media_dir'], $this->app->state('companies/'.$id.'/media'));
                }
            }
            $version = (int)$pdo->query('SELECT version FROM `'.$prefix.'migrations`')->fetchColumn();
            \Multiempresa\Perfex\TenantSchema::install($pdo, $prefix, $id);
            $release = $this->app->db->one('SELECT r.* FROM pme_releases r JOIN pme_gate g ON r.id=g.release_id WHERE g.id=1');
            if($release&&$version<(int)$release['version']){
                // A pinned bootstrap can finish on an earlier schema; migrate it with a backup next.
                $this->app->db->exec("UPDATE pme_companies SET schema_version=?,status='suspended' WHERE id=?",[$version,$id]);
                (new DatabaseProvisioning($this->app))->grantTables($id);
                $controlLock->close();
                (new CoreMaintenance($this->app))->ensure($id);
                $controlLock=new \Multiempresa\Infrastructure\AdvisoryLock($this->app,'updates');
                $version=(int)$pdo->query('SELECT version FROM `'.$prefix.'migrations`')->fetchColumn();
                $release=$this->app->db->one('SELECT r.* FROM pme_releases r JOIN pme_gate g ON r.id=g.release_id WHERE g.id=1');
            }
            if (!$release || $version !== (int)$release['version']) {
                throw new Failure('schema_version', 'A versão do banco não corresponde à aplicação instalada. O banco foi preservado.');
            }
            $pdo->exec("DELETE FROM `{$prefix}modules` WHERE module_name='multiempresa'");
            (new DatabaseProvisioning($this->app))->grantTables($id);
            $this->app->db->transaction(function () use ($id, $actor, $bindings, $version, $adopt, $activate) {
                foreach($bindings as [$userId,$staffId]){$this->app->db->exec('DELETE FROM pme_memberships WHERE company_id=? AND user_id=?',[$id,$userId]);$this->app->db->exec('INSERT INTO pme_memberships(company_id,user_id,staff_id) VALUES(?,?,?)',[$id,$userId,$staffId]);}
                $this->app->db->exec('UPDATE pme_companies SET status=?,schema_version=? WHERE id=?', [$activate?'active':'suspended',$version,$id]);
                $this->app->audit->log($adopt ? 'company.adopted' : 'company.created', $actor['id'], $id);
            });
            return $id;
        } catch (\Throwable $e) {
            $this->app->db->exec("UPDATE pme_companies SET status='failed',error_code=? WHERE id=?", [$e instanceof Failure ? $e->reason : 'provision_failed',$id]);
            $this->app->audit->log('company.provision_failed', $actor['id'], $id);
            throw $e;
        }
    }
    private function copyUploads(string $source, string $target): void
    {
        $base = realpath($source);
        if (!$base) {
            throw new Failure('uploads_source', 'Diretório de anexos original não encontrado.');
        }
        Files::mkdir($target);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $file) {
            if ($file->isLink()) {
                throw new Failure('uploads_symlink', 'Revise links simbólicos dos anexos antes da importação.');
            }
            $dest = $target.'/'.substr($file->getPathname(), strlen($base) + 1);
            if ($file->isDir()) {
                Files::mkdir($dest);
            } elseif (!copy($file->getPathname(), $dest)) {
                throw new Failure('uploads_copy', 'Falha ao copiar anexo.');
            } else {
                chmod($dest, 0600);
            }
        }
    }
}
