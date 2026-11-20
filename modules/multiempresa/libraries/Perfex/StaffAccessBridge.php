<?php
declare(strict_types=1);
namespace Multiempresa\Perfex;

use Multiempresa\Application\{App,StaffAccess,Installation};
use Multiempresa\Domain\Failure;

final class StaffAccessBridge
{
    private static bool $registered=false;
    private static string $staffId='';
    private static ?array $pending=null;
    public static function register(): void
    {
        // Installers run without an interactive identity and must not grant employee access.
        if(self::$registered||!empty($GLOBALS['pme_worker'])){return;}self::$registered=true;
        hooks()->add_action('staff_member_edit_view_profile',static function($id){self::$staffId=(string)$id;});
        hooks()->add_action('staff_render_permissions',[self::class,'render']);
        hooks()->add_filter('before_create_staff_member',[self::class,'filter'],5,1);
        hooks()->add_filter('before_update_staff_member',[self::class,'filter'],5,2);
        hooks()->add_action('before_delete_staff_member',[self::class,'guardManagement']);
        hooks()->add_filter('before_staff_status_change',static function($status){self::guardManagement();return $status;},5,2);
        hooks()->add_action('staff_member_created',[self::class,'created']);
    }
    private static function environment(): array
    {
        $app=$GLOBALS['pme_app']??App::environment();$ctx=$GLOBALS['pme_context']??null;
        if($ctx){$actor=$app->auth->refresh($ctx->principal['user_id']);}
        else{$actor=$app->auth->syncNative((int)get_staff_user_id());}
        return [$app,$actor,$ctx?->companyId];
    }
    public static function render(): void
    {
        if(!is_admin()){return;}
        [$app,$actor,$context]=self::environment();$service=new StaffAccess($app);$policy=(new \Multiempresa\Application\UserPolicies($app))->get();
        $member=self::$staffId!==''?get_instance()->db->where('staffid',(int)self::$staffId)->get(db_prefix().'staff')->row_array():null;
        $user=$member?$app->db->one('SELECT * FROM pme_users WHERE email=?',[strtolower($member['email'])]):null;
        $companies=$service->manageable($actor,$context,$user['id']??null);
        $selected=$user?array_column($app->db->all('SELECT company_id FROM pme_memberships WHERE user_id=? AND active=1',[$user['id']]),'company_id'):($context&&$policy['tenant_new_staff_access']?[$context]:[]);
        if(!$context&&$service->primaryAllowed($user['id']??null)){
            array_unshift($companies,['id'=>Installation::ID,'name'=>(new Installation($app))->get()['name']]);
            if((!$user&&$policy['central_staff_primary_default'])||($user&&(new Installation($app))->canAccess($user))){$selected[]=Installation::ID;}
        }
        echo '<div class="panel_s"><div class="panel-body"><h4 class="tw-mt-0">Acesso às empresas</h4><p class="text-muted">Selecione onde este colaborador pode entrar. As permissões de trabalho são definidas em cada empresa. No próximo login, será aberta a última empresa autorizada.</p><input type="hidden" name="multiempresa_access_present" value="1">';
        $org=$user?$app->catalog->organizationFor($user['id']):($context?$app->catalog->company($context)['organization_id']:(new Installation($app))->get()['organization_id']);
        $orgName=$org?($app->db->one('SELECT name FROM pme_organizations WHERE id=?',[$org])['name']??''):'Sem organização';
        echo '<p class="alert alert-info">Organização do colaborador: '.htmlspecialchars($orgName,ENT_QUOTES,'UTF-8').'. Somente empresas desta organização podem ser vinculadas.'.(!$context?' Administrar grupos pela central não cria acesso operacional.':'').'</p>';
        foreach($companies as $company){$id=$company['id'];$protected=$id===Installation::ID&&!empty($user['platform_admin']);
            echo '<div class="checkbox"><input type="checkbox" id="pme-staff-'.htmlspecialchars($id).'" name="multiempresa_companies[]" value="'.htmlspecialchars($id).'"'.(in_array($id,$selected,true)?' checked':'').($protected?' disabled':'').'><label for="pme-staff-'.htmlspecialchars($id).'">'.htmlspecialchars($company['name'],ENT_QUOTES,'UTF-8').($id===Installation::ID?' — principal':'').'</label></div>';
            if($protected){echo '<input type="hidden" name="multiempresa_companies[]" value="installation">';}
        }
        if($context){echo '<p class="text-muted">Para alterar o acesso a outras empresas, entre em contato com o suporte.</p>';}
        echo '</div></div>';
    }
    public static function guardManagement(): void
    {
        [$app,$actor,$context]=self::environment();if($context&&empty($actor['platform_admin'])&&!(new \Multiempresa\Application\UserPolicies($app))->get()['tenant_staff_management']){show_error(\Multiempresa\Http\TenantError::message(403),403);exit;}
    }
    public static function filter(array $data,$id=null): array
    {
        self::guardManagement();
        [$app,$actor,$context]=self::environment();$service=new StaffAccess($app);
        try{$service->assertEmailScope((string)($data['email']??''),$context);}catch(Failure $e){show_error(htmlspecialchars(self::feedback($app,$actor,$context,$e),ENT_QUOTES,'UTF-8'),$e->status);exit;}
        $present=array_key_exists('multiempresa_access_present',$data);$selected=$data['multiempresa_companies']??[];
        unset($data['multiempresa_access_present'],$data['multiempresa_companies']);
        if(!$present){return $data;}
        if(!is_admin()||!is_array($selected)){show_error('Você não pode alterar o acesso às empresas.',403);exit;}
        try{
            [$app,$actor,$context]=self::environment();$service=new StaffAccess($app);
            $target=null;if($id){$targetStaff=get_instance()->db->where('staffid',(int)$id)->get(db_prefix().'staff')->row_array();$target=$context?($app->db->one('SELECT id FROM pme_users WHERE email=?',[strtolower($targetStaff['email']??'')])['id']??null):($app->db->one('SELECT id FROM pme_users WHERE native_staff_id=?',[(int)$id])['id']??null);}
            $selected=$service->validate($actor,$context,$selected,$target);
            if($id){
                $staff=get_instance()->db->where('staffid',(int)$id)->get(db_prefix().'staff')->row_array();
                if(!$staff){throw new Failure('staff_missing','Colaborador não encontrado.',404);}
                // A permissions-only save may not emit staff_member_updated in Perfex.
                $service->apply($actor,$staff,$context,$selected);
            }else{self::$pending=[$actor,$context,$selected];}
        }catch(Failure $e){show_error(htmlspecialchars(self::feedback($app,$actor,$context,$e),ENT_QUOTES,'UTF-8'),$e->status);exit;}
        return $data;
    }
    public static function created($id): void
    {
        $app=$GLOBALS['pme_app']??App::environment();$staff=get_instance()->db->where('staffid',(int)$id)->get(db_prefix().'staff')->row_array();
        if(!self::$pending){
            [$app,$actor,$context]=self::environment();$service=new StaffAccess($app);$policy=(new \Multiempresa\Application\UserPolicies($app))->get();
            try{$user=$service->identity($staff,$context);if(!$context){$service->savePreference($user['id'],['principal_access'=>(int)(!empty($user['platform_admin'])||$policy['central_staff_primary_default'])]);}elseif($policy['tenant_new_staff_access']){$service->apply($actor,$staff,$context,[$context]);}}catch(Failure $e){set_alert('warning','Colaborador salvo. '.self::feedback($app,$actor,$context,$e));}return;
        }
        [$actor,$context,$selected]=self::$pending;self::$pending=null;
        try{(new StaffAccess($app))->apply($actor,$staff,$context,$selected);}
        catch(Failure $e){set_alert('warning','Colaborador salvo. '.self::feedback($app,$actor,$context,$e));}
    }
    private static function feedback(App $app,array $actor,?string $context,Failure $e): string
    {
        if(!$context){return $e->getMessage();}
        try{(new \Multiempresa\Application\ExecutionLog($app))->record('staff.access','failed',$actor['id'],$context,null,null,\Multiempresa\Application\ExecutionLog::exception($e));}catch(\Throwable){error_log('Staff access diagnosis could not be recorded.');}
        return \Multiempresa\Http\TenantError::message($e->status);
    }

}
