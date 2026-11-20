<?php

declare(strict_types=1);

namespace Multiempresa\Application;

use Multiempresa\Domain\{Id,Failure};

final class Catalog
{
    public function __construct(private App $app)
    {
    }
    public function company(string $id): array
    {
        return $this->app->db->one('SELECT c.*,o.name AS organization_name,o.slug AS organization_slug,o.active AS organization_active FROM pme_companies c JOIN pme_organizations o ON o.id=c.organization_id WHERE c.id=?', [$id])
            ?? throw new Failure('company_not_found', 'Empresa não encontrada.', 404);
    }
    public function bySlug(string $org, string $company): array
    {
        $row = $this->app->db->one('SELECT c.id FROM pme_companies c JOIN pme_organizations o ON o.id=c.organization_id WHERE o.slug=? AND c.slug=?', [$org,$company]);
        return $row ? $this->company($row['id']) : throw new Failure('company_not_found', 'Empresa não encontrada.', 404);
    }
    public function accessible(string $user,?string $organization=null): array
    {
        $assigned=$this->organizationFor($user);
        if(!$assigned||($organization!==null&&$organization!==$assigned)){return [];}
        $organization=$assigned;
        $rows=$this->app->db->all("SELECT c.*,o.slug AS organization_slug,m.staff_id FROM pme_companies c JOIN pme_memberships m ON m.company_id=c.id JOIN pme_organizations o ON o.id=c.organization_id WHERE m.user_id=? AND m.active=1 AND o.active=1 AND c.status='active'".($organization?' AND c.organization_id=?':'').' ORDER BY o.name,c.name', $organization?[$user,$organization]:[$user]);
        return $rows;
    }
    public function membership(string $user, string $company): array
    {
        $context=$this->company($company);$this->assertUserOrganization($user,$context['organization_id']);
        return $this->app->db->one("SELECT m.* FROM pme_memberships m JOIN pme_users u ON u.id=m.user_id JOIN pme_companies c ON c.id=m.company_id JOIN pme_organizations o ON o.id=c.organization_id WHERE m.user_id=? AND m.company_id=? AND m.active=1 AND u.active=1 AND o.active=1 AND c.status<>'removed'", [$user,$company])
            ?? throw new Failure('membership_required', 'Acesso à empresa não autorizado.', 403);
    }
    public function assertManager(array $actor, string $org): void
    {
        if ((int)$actor['platform_admin'] === 1) {
            return;
        }
        $this->assertUserOrganization($actor['id'],$org);
        if (!$this->app->db->one("SELECT user_id FROM pme_org_members WHERE organization_id=? AND user_id=? AND role='admin'", [$org,$actor['id']])) {
            throw new Failure('manager_required', 'Você não administra esta organização.', 403);
        }
    }
    public function createOrganization(array $actor, string $name, string $slug): string
    {
        if (!(int)$actor['platform_admin']) {
            throw new Failure('platform_admin', 'Somente o administrador global pode criar organizações.', 403);
        }
        $name = self::name($name);
        $slug = Id::slug($slug);
        if($this->app->db->one('SELECT id FROM pme_organizations WHERE slug=?',[$slug])){throw new Failure('organization_slug','Este identificador já pertence a uma organização.');}
        $id = Id::new();
        $this->app->db->transaction(function () use ($id, $name, $slug, $actor) {
            $this->app->db->exec('INSERT INTO pme_organizations(id,slug,name) VALUES(?,?,?)', [$id,$slug,$name]);
            $this->app->audit->log('organization.created', $actor['id'], null, ['organization_id' => $id]);
        });
        return $id;
    }
    public function grant(array $actor, string $company, string $user, int $staffId): void
    {
        $lock=new \Multiempresa\Infrastructure\AdvisoryLock($this->app,'company:'.$company);
        $c = $this->company($company);
        $this->assertManager($actor, $c['organization_id']);
        if($c['status']==='removed'){throw new Failure('company_removed','Esta empresa foi removida e não aceita novos acessos.',409);}
        $u = $this->app->db->one('SELECT id,email FROM pme_users WHERE id=? AND active=1', [$user]);
        if (!$u) {
            throw new Failure('user_not_found', 'Usuário global não encontrado.');
        }
        $this->assertUserOrganization($user,$c['organization_id']);
        $secrets = $this->app->vault->open($c['secrets']);
        $pdo = $this->app->storage($c['mode'])->connect($secrets['connection']);
        $stmt = $pdo->prepare('SELECT staffid,active,email FROM `'.Id::prefix($c['table_prefix'], $c['mode']).'staff` WHERE staffid=?');
        $stmt->execute([$staffId]);
        $staff = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$staff || !(int)$staff['active'] || strtolower($staff['email']) !== $u['email']) {
            throw new Failure('staff_link', 'O funcionário deve estar ativo e ter o mesmo e-mail da identidade global.');
        }
        $this->app->db->transaction(function () use ($company, $user, $staffId, $actor) {
            $this->app->db->exec('DELETE FROM pme_memberships WHERE company_id=? AND user_id=?', [$company,$user]);
            $this->app->db->exec('INSERT INTO pme_memberships(company_id,user_id,staff_id) VALUES(?,?,?)', [$company,$user,$staffId]);
            $this->app->audit->log('membership.granted', $actor['id'], $company, ['user_id' => $user,'staff_id' => $staffId]);
        });
    }
    public function revoke(array $actor, string $company, string $user): void
    {
        $c = $this->company($company);
        $this->assertManager($actor, $c['organization_id']);
        $this->app->db->transaction(function () use ($company, $user, $actor) {
            $this->app->db->exec('UPDATE pme_memberships SET active=0 WHERE company_id=? AND user_id=?', [$company,$user]);
            $this->app->db->exec('DELETE FROM pme_sessions WHERE company_id=? AND user_id=?', [$company,$user]);
            $this->app->audit->log('membership.revoked', $actor['id'], $company, ['user_id' => $user]);
        });
    }
    public function url(array $c): string
    {
        $host = $this->app->db->one('SELECT host FROM pme_hosts WHERE company_id=? AND verified=1 ORDER BY host', [$c['id']]);
        if ($host) {
            return 'https://'.$host['host'].'/';
        }
        $orgHost=$this->app->db->one('SELECT host FROM pme_organization_hosts WHERE organization_id=? AND verified=1 ORDER BY host',[$c['organization_id']]);
        if($orgHost){return 'https://'.$orgHost['host'].'/'.(new CompanyRoutes($this->app))->key($c['id']).'/';}
        return (new CompanyRoutes($this->app))->url($c['id']);
    }
    public static function name(string $name): string
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 190) {
            throw new Failure('name', 'Informe um nome com até 190 bytes.');
        }
        return $name;
    }
    /** Identity ownership is independent from grants and from matching names/e-mails. */
    public function organizationFor(string $user): ?string
    {
        $u=$this->app->db->one('SELECT id,native_staff_id FROM pme_users WHERE id=?',[$user]);if(!$u){return null;}
        $preference=(new StaffAccess($this->app))->preference($user);
        if(!empty($preference['identity_company'])){
            return $this->app->db->one('SELECT c.organization_id FROM pme_companies c JOIN pme_organizations o ON o.id=c.organization_id WHERE c.id=?',[$preference['identity_company']])['organization_id']??null;
        }
        if(empty($u['native_staff_id'])){return null;}
        // A native identity belongs to the installation, not to the creator's company grants.
        $installation=(new Installation($this->app))->get();
        return $installation['organization_id']?:null;
    }
    public function assertUserOrganization(string $user,string $organization,bool $bind=false): void
    {
        $assigned=$this->organizationFor($user);
        if(!$assigned||$assigned!==$organization){throw new Failure('organization_scope','O colaborador e a empresa devem pertencer à mesma organização. A administração global não concede vínculo operacional.',403);}
    }
    public function canEnter(string $user,string $company): bool
    {
        try{$this->membership($user,$company);return true;}catch(Failure){return false;}
    }
}
