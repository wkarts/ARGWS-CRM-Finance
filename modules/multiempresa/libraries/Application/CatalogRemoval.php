<?php
declare(strict_types=1);
namespace Multiempresa\Application;
use Multiempresa\Domain\Failure;

/** Physical catalog deletion after the confirmed external-resource operation has completed. */
final class CatalogRemoval
{
    public function __construct(private App $app) {}
    public function company(string $id,string $keepPlan=''): void
    {
        $organization=$this->app->db->one('SELECT organization_id FROM pme_companies WHERE id=?',[$id])['organization_id']??null;
        $this->app->db->transaction(function()use($id,$keepPlan,$organization){
            foreach(['events','endpoints','tokens','tickets','sessions','memberships','hosts','certificates','profiles','onboardings','module_reviews','modules','update_steps','resources'] as $table){$this->app->db->exec('DELETE FROM pme_'.$table.' WHERE company_id=?',[$id]);}
            $this->app->db->exec('DELETE FROM pme_switch_tickets WHERE source_company=? OR target_company=?',[$id,$id]);
            $this->app->db->exec('DELETE FROM pme_jobs WHERE target=?',[$id]);
            $this->app->db->exec('DELETE FROM pme_settings WHERE name IN (?,?,?)',['cron:'.$id,'core:'.$id,'core_rollback:'.$id]);
            $this->app->db->exec('DELETE FROM pme_settings WHERE name LIKE ?',['module:'.$id.':%']);
            $this->app->db->exec("DELETE FROM pme_settings WHERE name='modules-scan:cursor' AND value=?",[$id]);
            $this->app->db->exec('DELETE FROM pme_plans WHERE company_id=? AND id<>?',[$id,$keepPlan]);
            $this->app->db->exec('DELETE FROM pme_routes WHERE target_id=?',[$id]);
            $this->app->db->exec('UPDATE pme_user_preferences SET last_company_id=NULL WHERE last_company_id=?',[$id]);
            $this->app->db->exec('DELETE FROM pme_companies WHERE id=?',[$id]);
            $this->history($id,false,$keepPlan);
            (new IdentityCleanup($this->app))->company($id,$organization);
        });
    }
    public function organization(string $id,string $keepPlan=''): void
    {
        if($this->app->db->one('SELECT id FROM pme_companies WHERE organization_id=? LIMIT 1',[$id])){throw new Failure('organization_companies','Há empresas pendentes de exclusão nesta organização.',409);}
        $this->app->db->transaction(function()use($id,$keepPlan){
            foreach(['org_members','user_organizations','organization_hosts','organization_config','switch_tickets','resources'] as $table){$this->app->db->exec('DELETE FROM pme_'.$table.' WHERE organization_id=?',[$id]);}
            $this->app->db->exec('DELETE FROM pme_plans WHERE organization_id=? AND id<>?',[$id,$keepPlan]);
            $this->app->db->exec('DELETE FROM pme_organizations WHERE id=?',[$id]);
            $this->history($id,true,$keepPlan);
        });
    }
    public function history(string $id,bool $organization=false,string $keepPlan=''): void
    {
        // Purge scoped history too: deletion is not a hidden row or an identifier reservation.
        if(!preg_match('/\A[a-f0-9]{32}\z/',$id)){throw new Failure('deletion_scope','Identificador de exclusão inválido.');}
        $needle='%'.$id.'%';
        $this->app->db->exec('DELETE FROM pme_audit WHERE company_id=? OR details LIKE ?',[$id,$needle]);
        $column=$organization?'organization_id':'company_id';
        $this->app->db->exec('DELETE FROM pme_execution_logs WHERE '.$column.'=? OR details LIKE ?',[$id,$needle]);
        $this->app->db->exec('DELETE FROM pme_plans WHERE id<>? AND impact LIKE ?',[$keepPlan,$needle]);
        foreach($this->app->db->all("SELECT name,value FROM pme_settings WHERE name LIKE 'uninstall:%'") as $row){
            $state=$this->app->vault->open($row['value']);
            if(($state['status']??'')!=='running'&&str_contains(json_encode($state,JSON_THROW_ON_ERROR),$id)){$this->app->db->exec('DELETE FROM pme_settings WHERE name=?',[$row['name']]);}
        }
    }
    public function retireLegacyTombstones(): void
    {
        // Previously completed logical deletions lose only their catalog rows. Preserved external resources stay untouched.
        foreach($this->app->db->all("SELECT id,organization_id FROM pme_companies WHERE status='removed'") as $c){
            if($this->app->db->one("SELECT id FROM pme_plans WHERE (company_id=? OR (organization_id=? AND operation='delete_organization')) AND status IN ('running','failed') LIMIT 1",[$c['id'],$c['organization_id']])){continue;}
            $this->company($c['id']);
        }
        foreach($this->app->db->all("SELECT organization_id FROM pme_organization_config WHERE status='removed'") as $r){
            if(!$this->app->db->one('SELECT id FROM pme_companies WHERE organization_id=? LIMIT 1',[$r['organization_id']])){$this->organization($r['organization_id']);}
        }
    }
}
