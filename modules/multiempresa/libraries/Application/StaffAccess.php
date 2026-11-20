<?php
declare(strict_types=1);
namespace Multiempresa\Application;

use Multiempresa\Domain\{Failure,Id};

/** Company grants share an identity; permissions and staff roles stay local to each CRM. */
final class StaffAccess
{
    public function __construct(private App $app) {}
    public function preference(string $user): array
    {
        return $this->app->db->one('SELECT * FROM pme_user_preferences WHERE user_id=?',[$user])??[];
    }
    public function remember(string $user,string $company): void
    {
        if($company===Installation::ID){$actor=$this->app->db->one('SELECT * FROM pme_users WHERE id=?',[$user]);if(!$actor||!(new Installation($this->app))->canAccess($actor)){return;}}
        else{$this->app->catalog->membership($user,$company);}
        $this->savePreference($user,['last_company_id'=>$company]);
    }
    public function savePreference(string $user,array $values): void
    {
        $values=array_intersect_key($values,array_flip(['principal_access','last_company_id','identity_company','disabled_on_uninstall']));
        if(!$values){return;}
        $fields=array_keys($values);$update=[];
        foreach($fields as $field){$update[]=$field.'='.($this->app->db->driver()==='mysql'?'VALUES('.$field.')':'excluded.'.$field);}
        $suffix=$this->app->db->driver()==='mysql'?' ON DUPLICATE KEY UPDATE ':' ON CONFLICT(user_id) DO UPDATE SET ';
        $this->app->db->exec('INSERT INTO pme_user_preferences(user_id,'.implode(',',$fields).') VALUES('.implode(',',array_fill(0,count($values)+1,'?')).')'.$suffix.implode(',',$update),array_merge([$user],array_values($values)));
    }

    public function destination(array $actor): ?string
    {
        $last=$this->preference($actor['id'])['last_company_id']??Installation::ID;
        if($last===Installation::ID&&(new Installation($this->app))->canAccess($actor)){return $last;}
        $companies=$this->app->catalog->accessible($actor['id']);
        foreach($companies as $company){if($company['id']===$last&&$this->localActive($actor,$company)){return $last;}}
        if((new Installation($this->app))->canAccess($actor)){return Installation::ID;}
        foreach($companies as $company){if($this->localActive($actor,$company)){return $company['id'];}}
        return null;
    }
    private function localActive(array $actor,array $company): bool
    {
        try{
            $pdo=$this->app->storage($company['mode'])->connect($this->app->vault->open($company['secrets'])['connection']);
            $q=$pdo->prepare('SELECT active,email FROM `'.Id::prefix($company['table_prefix'], $company['mode']).'staff` WHERE staffid=?');$q->execute([$company['staff_id']]);$local=$q->fetch(\PDO::FETCH_ASSOC);
            return $local&&(int)$local['active']&&strtolower($local['email'])===$actor['email'];
        }catch(\Throwable){return false;}
    }
    public function manageable(array $actor,?string $context,?string $user=null): array
    {
        if($context){$company=$this->app->catalog->company($context);if($user){$this->app->catalog->assertUserOrganization($user,$company['organization_id']);}return [$company];}
        if(empty($actor['platform_admin'])){return [];}
        $organization=$user?$this->app->catalog->organizationFor($user):((new Installation($this->app))->get()['organization_id']?:null);
        if(!$organization){return [];}
        return $this->app->db->all("SELECT c.*,o.name AS organization_name FROM pme_companies c JOIN pme_organizations o ON o.id=c.organization_id WHERE c.organization_id=? AND o.active=1 AND c.status IN ('active','suspended') ORDER BY c.name",[$organization]);
    }
    public function primaryAllowed(?string $user): bool
    {
        if(!$user){return true;}
        $preference=$this->preference($user);
        if(empty($preference['identity_company'])){return true;}
        $org=$this->app->catalog->organizationFor($user);$installation=(new Installation($this->app))->get();
        return $org!==null&&$installation['active']&&$installation['organization_id']===$org;
    }
    public function validate(array $actor,?string $context,array $selected,?string $user=null): array
    {
        $allowed=array_column($this->manageable($actor,$context,$user),'id');
        if(!$context&&!empty($actor['platform_admin'])&&$this->primaryAllowed($user)){$allowed[]=Installation::ID;}
        foreach($selected as $id){if(!is_string($id)||!in_array($id,$allowed,true)){throw new Failure('membership_scope','Selecione somente empresas da organização deste colaborador.',403);}}
        return array_values(array_unique($selected));
    }
    public function assertEmailScope(string $email,?string $context): void
    {
        if(!$context||$email===''){return;}
        $native=Id::identifier($this->app->config['native_prefix'].'staff');$q=$this->app->db->pdo->prepare('SELECT staffid FROM `'.$native.'` WHERE LOWER(email)=?');$q->execute([strtolower(trim($email))]);$id=$q->fetchColumn();
        if($id){$user=$this->app->auth->syncNative((int)$id);$this->app->catalog->assertUserOrganization($user['id'],$this->app->catalog->company($context)['organization_id']);}
    }
    public function identity(array $staff,?string $context,bool $exclusive=false): array
    {
        if(!$context){return $this->app->auth->syncNative((int)$staff['staffid']);}
        $email=Id::email($staff['email']);$table=Id::identifier($this->app->config['native_prefix'].'staff');
        $lock=new \Multiempresa\Infrastructure\AdvisoryLock($this->app,'staff-identity:'.$email);
        $q=$this->app->db->pdo->prepare('SELECT staffid,active FROM `'.$table.'` WHERE LOWER(email)=?');$q->execute([$email]);$native=$q->fetch(\PDO::FETCH_ASSOC);
        if($native){
            if(!(int)$native['active']){throw new Failure('identity_disabled','Este colaborador está desativado na instalação principal.',403);}
            $mapped=$this->app->auth->syncNative((int)$native['staffid']);$this->app->catalog->assertUserOrganization($mapped['id'],$this->app->catalog->company($context)['organization_id']);
            if($exclusive){$existing=$this->app->db->one('SELECT id FROM pme_users WHERE native_staff_id=?',[(int)$native['staffid']]);if(!$existing||($this->preference($existing['id'])['identity_company']??null)!==$context){throw new Failure('administrator_exists','Este e-mail já pertence a outro cadastro de colaborador.',409);}}
            // Never copy a tenant password or administrator bit into an existing central identity.
            return $this->app->auth->syncNative((int)$native['staffid']);
        }
        $columns=['email','firstname','lastname','password','datecreated','active','admin','is_not_staff'];
        $values=[$email,$staff['firstname'],$staff['lastname'],$staff['password'],date('Y-m-d H:i:s'),1,0,0];
        return $this->app->db->transaction(function()use($table,$columns,$values,$context){
            $q=$this->app->db->pdo->prepare('INSERT INTO `'.$table.'` (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')');$q->execute($values);
            $user=$this->app->auth->syncNative((int)$this->app->db->pdo->lastInsertId());
            $this->savePreference($user['id'],['principal_access'=>0,'identity_company'=>$context]);
            return $user;
        });
    }
    public function apply(array $actor,array $staff,?string $context,array $selected): void
    {
        $user=$this->identity($staff,$context);$selected=$this->validate($actor,$context,$selected,$user['id']);
        if(!$context){
            $principal=in_array(Installation::ID,$selected,true);
            if(!empty($user['platform_admin'])){$principal=true;}
            $this->savePreference($user['id'],['principal_access'=>(int)$principal]);
        }
        foreach($this->manageable($actor,$context,$user['id']) as $company){
            $id=$company['id'];$granted=in_array($id,$selected,true);
            if(!$granted){
                $this->app->db->exec('UPDATE pme_memberships SET active=0 WHERE user_id=? AND company_id=?',[$user['id'],$id]);
                $this->app->db->exec('DELETE FROM pme_sessions WHERE user_id=? AND company_id=?',[$user['id'],$id]);
                $this->app->db->exec('UPDATE pme_tokens SET revoked=1 WHERE user_id=? AND company_id=?',[$user['id'],$id]);
                $this->app->db->exec('DELETE FROM pme_tickets WHERE user_id=? AND company_id=?',[$user['id'],$id]);
                continue;
            }
            if($context===$id){$localId=(int)$staff['staffid'];}
            else{
                $pdo=$this->app->storage($company['mode'])->connect($this->app->vault->open($company['secrets'])['connection']);
                $table=Id::identifier($company['table_prefix'].'staff');
                $q=$pdo->prepare('SELECT staffid,active FROM `'.$table.'` WHERE LOWER(email)=?');$q->execute([$user['email']]);$local=$q->fetch(\PDO::FETCH_ASSOC);
                if($local){
                    if(!(int)$local['active']){throw new Failure('staff_disabled','Ative o colaborador em '.$company['name'].' antes de liberar o acesso.');}
                    $localId=(int)$local['staffid'];
                }else{
                    // Created with no administrative role; native permissions can be assigned in that company.
                    $q=$pdo->prepare('INSERT INTO `'.$table.'` (email,firstname,lastname,password,datecreated,active,admin,is_not_staff) VALUES(?,?,?,?,?,1,0,0)');
                    $q->execute([$user['email'],$staff['firstname'],$staff['lastname'],$staff['password'],date('Y-m-d H:i:s')]);$localId=(int)$pdo->lastInsertId();
                }
            }
            $this->app->db->transaction(function()use($id,$user,$localId){
                $this->app->db->exec('DELETE FROM pme_memberships WHERE company_id=? AND user_id=?',[$id,$user['id']]);
                $this->app->db->exec('INSERT INTO pme_memberships(company_id,user_id,staff_id,active) VALUES(?,?,?,1)',[$id,$user['id'],$localId]);
            });
        }
        $this->app->audit->log('staff.company_access_updated',$actor['id'],$context,['user_id'=>$user['id'],'companies'=>$selected]);
    }
}
