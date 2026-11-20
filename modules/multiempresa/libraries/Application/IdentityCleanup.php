<?php
declare(strict_types=1);
namespace Multiempresa\Application;
use Multiempresa\Domain\Id;

/** Retires only login identities created by this module, never pre-existing principal employees. */
final class IdentityCleanup
{
    public function __construct(private App $app) {}
    public function company(string $company,?string $organization): void
    {
        foreach($this->app->db->all('SELECT user_id FROM pme_user_preferences WHERE identity_company=?',[$company]) as $row){$this->retire($row['user_id'],$organization);}
    }
    private function retire(string $id,?string $organization): void
    {
        $user=$this->app->db->one('SELECT * FROM pme_users WHERE id=?',[$id]);if(!$user){$this->app->db->exec('DELETE FROM pme_user_preferences WHERE user_id=?',[$id]);return;}
        $preferences=(new StaffAccess($this->app))->preference($id);if(empty($preferences['identity_company'])){return;}
        $native=Id::identifier($this->app->config['native_prefix'].'staff');$q=$this->app->db->pdo->prepare('SELECT admin FROM `'.$native.'` WHERE staffid=?');$q->execute([$user['native_staff_id']]);$staff=$q->fetch(\PDO::FETCH_ASSOC);
        $installation=(new Installation($this->app))->get();
        if(!empty($staff['admin'])||(!empty($preferences['principal_access'])&&$organization&&$installation['organization_id']===$organization)){
            (new StaffAccess($this->app))->savePreference($id,['identity_company'=>null,'last_company_id'=>null]);return;
        }
        $remaining=$this->app->db->all('SELECT c.id,c.organization_id FROM pme_memberships m JOIN pme_companies c ON c.id=m.company_id WHERE m.user_id=? AND m.active=1 ORDER BY c.id',[$id]);
        // A shared identity survives only in the original organization, under a still-existing grant.
        if($organization){$remaining=array_values(array_filter($remaining,static fn($c)=>$c['organization_id']===$organization));}
        else{$remaining=[];} // Missing ownership never authorizes inferring an organization from legacy grants.
        if($remaining){
            (new StaffAccess($this->app))->savePreference($id,['identity_company'=>$remaining[0]['id'],'last_company_id'=>null]);
            $this->app->db->exec('DELETE FROM pme_sessions WHERE user_id=?',[$id]);
            $this->app->db->exec('DELETE FROM pme_switch_tickets WHERE user_id=?',[$id]);
            return;
        }
        foreach(['tokens','tickets','switch_tickets','sessions','memberships','org_members','user_organizations','user_preferences'] as $table){$this->app->db->exec('DELETE FROM pme_'.$table.' WHERE user_id=?',[$id]);}
        foreach(['jobs','plans','audit','execution_logs'] as $table){$this->app->db->exec('DELETE FROM pme_'.$table.' WHERE actor_id=?',[$id]);}
        // Authentication metadata belongs exclusively to the module-created shadow login.
        foreach(['staff_permissions'=>'staff_id=?','staff_departments'=>'staffid=?','user_meta'=>'staff_id=?','user_auto_login'=>'user_id=? AND staff=1','dismissed_announcements'=>'userid=? AND staff=1','notifications'=>'touserid=?'] as $table=>$where){
            $name=Id::identifier($this->app->config['native_prefix'].$table);
            if($this->tableExists($name)){$q=$this->app->db->pdo->prepare('DELETE FROM `'.$name.'` WHERE '.$where);$q->execute([$user['native_staff_id']]);}
        }
        $this->app->db->exec('DELETE FROM pme_users WHERE id=?',[$id]);
        $q=$this->app->db->pdo->prepare('DELETE FROM `'.$native.'` WHERE staffid=? AND admin=0');$q->execute([$user['native_staff_id']]);
    }
    private function tableExists(string $name): bool
    {
        if($this->app->db->driver()==='mysql'){$q=$this->app->db->pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');}
        else{$q=$this->app->db->pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");}
        $q->execute([$name]);return (bool)$q->fetchColumn();
    }
    public function reconcile(): void
    {
        $this->app->db->transaction(function(){
            foreach($this->app->db->all('SELECT p.user_id FROM pme_user_preferences p LEFT JOIN pme_companies c ON c.id=p.identity_company WHERE p.identity_company IS NOT NULL AND c.id IS NULL') as $row){$this->retire($row['user_id'],null);}
            foreach($this->app->db->all('SELECT m.user_id,m.company_id,c.organization_id FROM pme_memberships m JOIN pme_companies c ON c.id=m.company_id') as $m){
                if($this->app->catalog->organizationFor($m['user_id'])===$m['organization_id']){continue;}
                foreach(['memberships','tokens','sessions','tickets'] as $table){$this->app->db->exec('DELETE FROM pme_'.$table.' WHERE user_id=? AND company_id=?',[$m['user_id'],$m['company_id']]);}
                $this->app->db->exec('DELETE FROM pme_switch_tickets WHERE user_id=?',[$m['user_id']]);
                $this->app->db->exec('UPDATE pme_user_preferences SET last_company_id=NULL WHERE user_id=? AND last_company_id=?',[$m['user_id'],$m['company_id']]);
            }
            foreach($this->app->db->all('SELECT user_id,organization_id FROM pme_org_members') as $row){if($this->app->catalog->organizationFor($row['user_id'])!==$row['organization_id']){$this->app->db->exec('DELETE FROM pme_org_members WHERE user_id=? AND organization_id=?',[$row['user_id'],$row['organization_id']]);}}
            foreach($this->app->db->all('SELECT user_id,organization_id FROM pme_user_organizations') as $row){if($this->app->catalog->organizationFor($row['user_id'])!==$row['organization_id']){$this->app->db->exec('DELETE FROM pme_user_organizations WHERE user_id=?',[$row['user_id']]);}}
            $this->app->db->exec('UPDATE pme_user_preferences SET last_company_id=NULL WHERE last_company_id IS NOT NULL AND last_company_id<>? AND last_company_id NOT IN (SELECT id FROM pme_companies)',[Installation::ID]);
        });
    }
}
