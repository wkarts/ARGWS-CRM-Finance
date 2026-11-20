<?php
declare(strict_types=1);
namespace Multiempresa\Application;
use Multiempresa\Infrastructure\{AdvisoryLock,Process};
use Multiempresa\Domain\Failure;
final class Scheduler
{
    public function __construct(private App $app) {}
    public function tick(): void {
        try { $lock=new AdvisoryLock($this->app,'scheduler'); }
        catch (Failure $e) { if ($e->reason==='operation_busy') { return; } throw $e; }
        $start=microtime(true);
        try {
            (new CoreMaintenance($this->app))->synchronize();
            // Failed automatic maintenance resumes without requiring an administrator to repair SQL.
            foreach($this->app->db->all("SELECT c.id FROM pme_companies c LEFT JOIN pme_settings s ON s.name=CONCAT('core:',c.id) JOIN pme_gate g ON g.id=1 JOIN pme_releases r ON r.id=g.release_id WHERE c.schema_version>0 AND (c.schema_version<>r.version OR s.name IS NOT NULL) AND c.status<>'removed' ORDER BY c.id") as $pending){
                if(microtime(true)-$start>20){break;}
                try{(new CoreMaintenance($this->app))->ensure($pending['id']);}catch(\Throwable $e){$this->app->audit->log('core.retry_pending',null,$pending['id'],['reason'=>$e instanceof Failure?$e->reason:'core_update_pending']);}
            }
            foreach($this->app->db->all("SELECT id,actor_id FROM pme_onboardings WHERE status IN ('failed','interrupted') ORDER BY updated_at LIMIT 20") as $workflow){
                if(microtime(true)-$start>30){break;}
                $actor=$this->app->db->one('SELECT * FROM pme_users WHERE id=? AND active=1',[$workflow['actor_id']]);
                if($actor){$this->app->db->exec('UPDATE pme_onboardings SET updated_at=? WHERE id=?',[time(),$workflow['id']]);try{(new Onboarding($this->app))->advance($actor,$workflow['id']);}catch(\Throwable $e){$this->app->audit->log('core.workflow_retry_pending',$actor['id'],null,['reason'=>$e instanceof Failure?$e->reason:'core_update_pending']);}}
            }
            $cursor=$this->app->db->one("SELECT value FROM pme_settings WHERE name='modules-scan:cursor'");
            foreach($this->app->db->all("SELECT id FROM pme_companies WHERE schema_version>0 AND status IN ('active','suspended','maintenance') ORDER BY (id>?) DESC,id",[$cursor['value']??'']) as $company){
                if(microtime(true)-$start>30){break;}
                $this->app->db->exec("INSERT INTO pme_settings(name,value) VALUES('modules-scan:cursor',?) ON DUPLICATE KEY UPDATE value=VALUES(value)",[$company['id']]);
                try{(new ModuleMaintenance($this->app))->ensure($company['id']);}catch(\Throwable $e){$this->app->audit->log('module.update_pending',null,$company['id'],['reason'=>$e instanceof Failure?$e->reason:'module_update_pending']);}
            }
            (new \Multiempresa\Integration\CustomHostnames($this->app))->refreshPending(2);
            (new \Multiempresa\Integration\DomainReadiness($this->app))->refreshPending(2);
            (new ControlJobs($this->app))->runOne();
            $events=new Events($this->app);
            for ($i=0;$i<25 && microtime(true)-$start<20;$i++) { if (!$events->runOne()) { break; } }
            $companies=$this->app->db->all("SELECT c.id,COALESCE(s.value,'0') AS last_run FROM pme_companies c JOIN pme_organizations o ON o.id=c.organization_id LEFT JOIN pme_settings s ON s.name=CONCAT('cron:',c.id) WHERE c.status='active' AND o.active=1 ORDER BY CAST(COALESCE(s.value,'0') AS UNSIGNED),c.id");
            foreach ($companies as $c) {
                if (time()-(int)$c['last_run']<300 || microtime(true)-$start>40) { continue; }
                $key='cron:'.$c['id'];
                $this->app->db->exec('INSERT INTO pme_settings(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',[$key,(string)time()]);
                try { Process::run([$this->app->config['php_binary'],dirname(__DIR__,2).'/scripts/tenant-worker.php'],['company_id'=>$c['id'],'operation'=>'cron'],120); }
                catch (\Throwable $e) { $this->app->audit->log('cron.failed',null,$c['id'],['reason'=>$e instanceof Failure?$e->reason:'cron_failed']); }
            }
            $this->app->db->exec('DELETE FROM pme_sessions WHERE expires_at<?',[time()]);
            $this->app->db->exec('DELETE FROM pme_tickets WHERE expires_at<?',[time()]);
            $this->app->db->exec('DELETE FROM pme_switch_tickets WHERE expires_at<?',[time()]);
            $this->app->db->exec("UPDATE pme_plans SET status='expired',payload=? WHERE status='prepared' AND expires_at<?",[$this->app->vault->seal([]),time()]);
            $this->app->db->exec('DELETE FROM pme_rate_limits WHERE expires_at<?',[time()]);
        } finally { $lock->close(); }
    }
}
