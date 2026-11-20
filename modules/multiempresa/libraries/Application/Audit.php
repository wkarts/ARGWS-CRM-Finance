<?php

declare(strict_types=1);

namespace Multiempresa\Application;

use Multiempresa\Infrastructure\{Database,Vault};
use Multiempresa\Domain\Id;

final class Audit
{
    public function __construct(private Database $db, private Vault $vault, private ?App $app=null)
    {
    }
    public function log(string $action, ?string $actor = null, ?string $company = null, array $details = []): void
    {
        // Only event codes and identifiers. Never serialize request bodies or connection secrets.
        $details=ExecutionLog::sanitize($details);
        $data = json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $id = Id::new();
        $time = time();
        $signature = $this->vault->mac(json_encode([$id,$actor,$company,$action,$data,$time], JSON_THROW_ON_ERROR));
        $this->db->exec('INSERT INTO pme_audit(id,actor_id,company_id,action,details,created_at,signature) VALUES(?,?,?,?,?,?,?)', [$id,$actor,$company,$action,$data,$time,$signature]);
        if($this->app){try{(new ExecutionLog($this->app))->record($action,str_contains($action,'failed')?'failed':'done',$actor,$company,$details['organization_id']??null,$details['execution_id']??null,$details);}catch(\PDOException $e){$missing=(string)$e->getCode()==='42S02'||($this->db->driver()==='sqlite'&&str_contains($e->getMessage(),'no such table'));if(!$missing){throw $e;}}}
    }
    public function verify(array $row): bool
    {
        return hash_equals($row['signature'], $this->vault->mac(json_encode([$row['id'],$row['actor_id'],$row['company_id'],$row['action'],$row['details'],(int)$row['created_at']], JSON_THROW_ON_ERROR)));
    }
}
