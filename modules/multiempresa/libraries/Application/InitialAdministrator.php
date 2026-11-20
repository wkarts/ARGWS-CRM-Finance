<?php
declare(strict_types=1);
namespace Multiempresa\Application;

use Multiempresa\Domain\{Failure,Id};

/** Initial tenant administrator is an explicit choice, independent from central management. */
final class InitialAdministrator
{
    public function __construct(private App $app) {}

    public function normalize(array $actor,array $input,bool $draft=false,?string $company=null): array
    {
        $policies=(new UserPolicies($this->app))->get();
        $mode=(string)($input['admin_mode']??$policies['initial_admin']);
        if(!in_array($mode,['new','current'],true)){throw new Failure('administrator_mode','Selecione o administrador inicial.');}
        $organization=(string)($input['organization_id']??($company?$this->app->catalog->company($company)['organization_id']:''));
        $sameOrganization=$organization!==''&&$this->app->catalog->organizationFor($actor['id'])===$organization;
        $grant=array_key_exists('grant_creator',$input)?filter_var($input['grant_creator'],FILTER_VALIDATE_BOOL):($sameOrganization&&$policies['grant_creator_default']);
        if(($mode==='current'||$grant)&&!$draft){$this->app->catalog->assertUserOrganization($actor['id'],$organization);}
        if($mode==='current'){return ['mode'=>'current','grant_creator'=>true];}
        $name=trim((string)($input['admin_name']??''));$email=trim((string)($input['admin_email']??''));$password=(string)($input['admin_password']??'');
        if(!$draft){
            if($name===''){throw new Failure('administrator_name','Informe o nome do administrador desta empresa.');}
            $name=Catalog::name($name);$email=Id::email($email);
            if(strlen($password)<12||strlen($password)>72){throw new Failure('administrator_password','Use uma senha de 12 a 72 caracteres para o administrador.');}
            if($email===$actor['email']){throw new Failure('administrator_exists','Para usar sua identidade, escolha Usar meu usuário no administrador inicial.');}
            $table=Id::identifier($this->app->config['native_prefix'].'staff');
            $q=$this->app->db->pdo->prepare('SELECT staffid FROM `'.$table.'` WHERE LOWER(email)=?');$q->execute([$email]);$native=$q->fetchColumn();
            if($native){
                $user=$this->app->db->one('SELECT id FROM pme_users WHERE native_staff_id=?',[(int)$native]);
                if(!$company||!$user||((new StaffAccess($this->app))->preference($user['id'])['identity_company']??null)!==$company){throw new Failure('administrator_exists','Este e-mail já possui uma identidade de colaborador. Use outro e-mail para o novo administrador; vínculos existentes são gerenciados em Usuários e acessos.');}
            }
        }
        return ['mode'=>'new','name'=>$name,'email'=>$email,'password'=>$password,'grant_creator'=>$grant];
    }

    /** Returns only the explicitly chosen memberships; existing roles and passwords are never overwritten. */
    public function install(array $actor,array $company,\PDO $pdo,array $configuration): array
    {
        $prefix=Id::prefix($company['table_prefix'], $company['mode']);$bindings=[];
        if(!empty($configuration['grant_creator'])||($configuration['mode']??'')==='current'){$this->app->catalog->assertUserOrganization($actor['id'],$company['organization_id']);}
        if(($configuration['mode']??'')==='new'){
            // Revalidate the selection; identity creation below also validates uniqueness under its lock.
            $configuration=$this->normalize($actor,['admin_mode'=>'new','admin_name'=>$configuration['name'],'admin_email'=>$configuration['email'],'admin_password'=>$configuration['password'],'grant_creator'=>$configuration['grant_creator']??false],false,$company['id']);
            $staff=$this->local($pdo,$prefix,$configuration['email'],$configuration['name'],$configuration['password']);
            $user=(new StaffAccess($this->app))->identity($staff,$company['id'],true);
            if(((new StaffAccess($this->app))->preference($user['id'])['identity_company']??null)!==$company['id']){throw new Failure('administrator_exists','A identidade mudou durante a criação. Revise o administrador antes de retomar.',409);}
            $bindings[]=[$user['id'],(int)$staff['staffid']];
        }elseif(($configuration['mode']??'')!=='current'){
            throw new Failure('administrator_required','Revise as etapas anteriores e defina o administrador inicial.');
        }
        if(!empty($configuration['grant_creator'])||$configuration['mode']==='current'){
            $staff=$this->local($pdo,$prefix,$actor['email'],$actor['name'],Id::token());$bindings[]=[$actor['id'],(int)$staff['staffid']];
        }
        return $bindings;
    }

    private function local(\PDO $pdo,string $prefix,string $email,string $name,string $password): array
    {
        $q=$pdo->prepare('SELECT * FROM `'.$prefix.'staff` WHERE LOWER(email)=?');$q->execute([$email]);$staff=$q->fetch(\PDO::FETCH_ASSOC);
        if(!$staff){$q=$pdo->prepare('INSERT INTO `'.$prefix.'staff` (email,firstname,lastname,password,datecreated,admin,active,is_not_staff) VALUES(?,?,?,?,?,1,1,0)');$q->execute([$email,$name,'',password_hash($password,PASSWORD_BCRYPT),date('Y-m-d H:i:s')]);$q=$pdo->prepare('SELECT * FROM `'.$prefix.'staff` WHERE staffid=?');$q->execute([(int)$pdo->lastInsertId()]);$staff=$q->fetch(\PDO::FETCH_ASSOC);}
        if(!(int)$staff['active']||!(int)$staff['admin']){throw new Failure('administrator_local','O administrador inicial já existe, mas está inativo ou sem perfil administrativo. Revise o cadastro local.');}
        return $staff;
    }
}
