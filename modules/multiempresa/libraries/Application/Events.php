<?php

declare(strict_types=1);

namespace Multiempresa\Application;

use Multiempresa\Domain\{Id,Failure};
use Multiempresa\Infrastructure\{Database,AdvisoryLock};
use Multiempresa\Http\Resolver;

final class Events
{
    public function __construct(private App $app)
    {
    }
    public function endpoint(array $actor, string $company, string $provider, string $processor): array
    {
        $lock=new AdvisoryLock($this->app,'company:'.$company);
        $c = $this->app->catalog->company($company);
        $this->app->catalog->assertManager($actor, $c['organization_id']);
        if($c['status']==='removed'){throw new Failure('company_removed','Esta empresa foi removida e não aceita novas integrações.',409);}
        $this->app->authenticator($provider);
        $this->app->processor($processor);
        $id = Id::new();
        $secret = Id::token();
        $this->app->db->exec('INSERT INTO pme_endpoints(id,company_id,provider,secret,token_hash,processor,created_at) VALUES(?,?,?,?,?,?,?)', [$id,$company,$provider,$this->app->vault->seal(['secret' => $secret]),hash('sha256', $secret),$processor,time()]);
        $this->app->audit->log('endpoint.created', $actor['id'], $company, ['endpoint_id' => $id,'processor' => $processor]);
        return ['id' => $id,'secret' => $secret,'url' => \Multiempresa\Http\Entry::url($this->app,$this->app->config['base_url'].'/hooks/'.$provider.'/'.$id)];
    }
    public function receive(string $provider, ?string $endpoint, array $headers, string $body): array
    {
        if (strlen($body) > ($this->app->config['max_body_bytes'] ?? 1048576)) {
            throw new Failure('payload_size', 'Evento muito grande.', 413);
        }
        $headers = array_change_key_case($headers, CASE_LOWER);
        if (!$endpoint) {
            $e = $this->app->db->one('SELECT * FROM pme_endpoints WHERE provider=? AND id=? AND active=1', [$provider,(string)($headers['x-pme-endpoint'] ?? '')]);
        } else {
            $e = $this->app->db->one('SELECT * FROM pme_endpoints WHERE provider=? AND id=? AND active=1', [$provider,$endpoint]);
        }
        if (!$e) {
            throw new Failure('webhook_endpoint', 'Integração não autorizada.', 401);
        }
        $secret = $this->app->vault->open($e['secret'])['secret'];
        $eventId = $this->app->authenticator($provider)->authenticate($headers, $body, $secret, time());
        $this->app->limits->hit('webhook:'.$e['id'], 1000, 60);
        $id = Id::new();
        $digest = hash('sha256', $body);
        try {
            $this->app->db->exec('INSERT INTO pme_events(id,endpoint_id,company_id,provider_event,digest,payload,status,available_at,created_at) VALUES(?,?,?,?,?,?,?,?,?)', [$id,$e['id'],$e['company_id'],$eventId,$digest,$this->app->vault->seal(['body' => $body]),'pending',time(),time()]);
        } catch (\PDOException $ex) {
            if (!Database::duplicate($ex)) {
                throw $ex;
            }
            $old = $this->app->db->one('SELECT id,digest FROM pme_events WHERE endpoint_id=? AND provider_event=?', [$e['id'],$eventId]);
            if (!$old || !hash_equals($old['digest'], $digest)) {
                throw new Failure('event_conflict', 'Identificador repetido com conteúdo divergente.', 409);
            }
            return ['accepted' => true,'duplicate' => true,'id' => $old['id']];
        }
        return ['accepted' => true,'duplicate' => false,'id' => $id];
    }
    public function runOne(): bool
    {
        if (($this->app->db->one('SELECT state FROM pme_gate WHERE id=1')['state'] ?? '') !== 'active') {
            return false;
        }
        $candidates = $this->app->db->all("SELECT e.company_id,MIN(e.created_at) AS oldest FROM pme_events e JOIN pme_companies c ON c.id=e.company_id WHERE c.status='active' AND e.available_at<=? AND (e.status='pending' OR (e.status='processing' AND e.lease_until<?)) GROUP BY e.company_id ORDER BY oldest,e.company_id LIMIT 100", [time(),time()]);
        $row = null;
        foreach ($candidates as $candidate) {
            try {
                $lock = new AdvisoryLock($this->app, 'company:'.$candidate['company_id']);
            } catch (Failure $e) {
                if ($e->reason === 'operation_busy') {
                    continue;
                }
                throw $e;
            }
            $row = $this->app->db->one("SELECT e.*,p.processor,p.active AS endpoint_active FROM pme_events e JOIN pme_endpoints p ON p.id=e.endpoint_id WHERE e.company_id=? AND e.available_at<=? AND (e.status='pending' OR (e.status='processing' AND e.lease_until<?)) ORDER BY e.created_at,e.id LIMIT 1", [$candidate['company_id'],time(),time()]);
            if ($row) {
                break;
            }
            $lock->close();
        }
        if (!$row) {
            return false;
        }
        $lease = Id::new();
        if ($this->app->db->exec("UPDATE pme_events SET status='processing',attempts=attempts+1,lease=?,lease_until=? WHERE id=? AND (status='pending' OR (status='processing' AND lease_until<?))", [$lease,time() + 900,$row['id'],time()]) !== 1) {
            return true;
        }
        try {
            if (!(int)$row['endpoint_active']) {
                throw new Failure('endpoint_disabled', 'Integração suspensa.');
            }
            $gate = new ReleaseGate($this->app);
            $release = $gate->enter($row['company_id']);
            $ctx = (new Resolver($this->app))->forCompany($row['company_id']);
            $row['body'] = $this->app->vault->open($row['payload'])['body'];
            $row['release'] = $release;
            $this->app->processor($row['processor'])->process($ctx, $row);
            $this->app->db->exec("UPDATE pme_events SET status='done',completed_at=?,lease=NULL,lease_until=NULL,error_code=NULL WHERE id=? AND lease=?", [time(),$row['id'],$lease]);
        } catch (\Throwable $e) {
            $attempts = (int)$row['attempts'] + 1;
            $code = $e instanceof Failure ? $e->reason : 'processor_failed';
            if ($code === 'maintenance') {
                $this->app->db->exec("UPDATE pme_events SET status='pending',attempts=attempts-1,available_at=?,lease=NULL,lease_until=NULL WHERE id=? AND lease=?", [time() + 30, $row['id'], $lease]);
                return true;
            }
            $review = in_array($code, ['needs_mapping','payment_conflict','payment_amount','unsupported_payment_event','endpoint_disabled'], true);
            $status = $review ? 'review' : ($attempts >= 8 ? 'dead' : 'pending');
            $this->app->db->exec('UPDATE pme_events SET status=?,available_at=?,lease=NULL,lease_until=NULL,error_code=? WHERE id=? AND lease=?', [$status,time() + min(3600, 2 ** $attempts * 15) + random_int(0, 10),$code,$row['id'],$lease]);
            $this->app->audit->log('event.failed', null, $row['company_id'], ['event_id' => $row['id'],'reason' => $code]);
        } finally {
            if (isset($gate)) {
                $gate->close();
            }
            $lock->close();
        }
        return true;
    }
    public function retry(array $actor, string $id): void
    {
        $event = $this->app->db->one('SELECT * FROM pme_events WHERE id=?', [$id]) ?? throw new Failure('event_missing', 'Evento não encontrado.', 404);
        $c = $this->app->catalog->company($event['company_id']);
        $this->app->catalog->assertManager($actor, $c['organization_id']);
        if($c['status']==='removed'){throw new Failure('company_removed','Esta empresa foi removida e não aceita reprocessamento.',409);}
        if (!in_array($event['status'], ['dead','review'], true)) {
            throw new Failure('event_state', 'Somente eventos retidos ou esgotados podem ser reabertos.');
        }
        $this->app->db->exec("UPDATE pme_events SET status='pending',attempts=0,available_at=?,error_code=NULL WHERE id=? AND status IN ('dead','review')", [time(),$id]);
        $this->app->audit->log('event.retried', $actor['id'], $c['id'], ['event_id' => $id]);
    }
}
