<?php
declare(strict_types=1);
namespace Multiempresa\Application;
use Multiempresa\Domain\{Failure,Id};

/** Explicit-membership SSO with a destination verifier cookie and one-use authorization. */
final class CompanySwitch
{
    public function __construct(private App $app) {}
    public function issue(array $session,string $source,string $target): string
    {
        if($session['company_id']!==$source){throw new Failure('switch_source','Contexto de origem inválido.',403);}
        $from=$this->app->catalog->company($source);$to=$this->app->catalog->company($target);
        if($from['organization_id']!==$to['organization_id']){throw new Failure('organization_scope','A alternância permite somente empresas da mesma organização.',403);}
        $this->app->catalog->membership($session['user_id'],$source);
        $this->app->catalog->membership($session['user_id'],$target);if($to['status']!=='active'){throw new Failure('company_unavailable','Empresa indisponível.');}
        $raw=Id::token();$this->app->db->exec('INSERT INTO pme_switch_tickets(token_hash,user_id,organization_id,source_company,target_company,auth_version,mfa,expires_at) VALUES(?,?,?,?,?,?,?,?)',[hash('sha256',$raw),$session['user_id'],$to['organization_id'],$source,$target,(int)$session['auth_version'],(int)$session['mfa'],time()+90]);return $raw;
    }
    public function pending(string $raw): array
    {
        return $this->app->db->one('SELECT * FROM pme_switch_tickets WHERE token_hash=? AND consumed=0 AND expires_at>?',[hash('sha256',$raw),time()])??throw new Failure('switch_expired','Seleção expirada. Selecione a empresa novamente.',403);
    }
    public function authorize(array $session,string $raw,string $challenge): array
    {
        $t=$this->pending($raw);if($t['user_id']!==$session['user_id']||$t['source_company']!==$session['company_id']||(int)$t['auth_version']!==(int)$session['auth_version']){throw new Failure('switch_identity','A seleção pertence a outra sessão.',403);}
        $target=$this->app->catalog->company($t['target_company']);$source=$this->app->catalog->company($t['source_company']);
        $this->app->catalog->membership($session['user_id'],$source['id']);
        if($target['organization_id']!==$t['organization_id']||$source['organization_id']!==$target['organization_id']){throw new Failure('organization_scope','A organização da empresa mudou. Selecione novamente dentro do mesmo grupo.',403);}
        return $this->app->db->transaction(function()use($session,$t,$target,$challenge){
            if($this->app->db->exec('UPDATE pme_switch_tickets SET consumed=1 WHERE token_hash=? AND consumed=0',[$t['token_hash']])!==1){throw new Failure('switch_expired','Seleção já utilizada.',403);}
            $code=$this->app->auth->issueTicket($session,$t['target_company'],$challenge);
            $this->app->db->exec('DELETE FROM pme_sessions WHERE token_hash=?',[$session['token_hash']]);
            $this->app->audit->log('company.switched',$session['user_id'],$target['id'],['source_company'=>$t['source_company'],'organization_id'=>$t['organization_id']]);return ['code'=>$code,'company'=>$target];
        });
    }
}
