<?php
defined('BASEPATH') or exit('No direct script access allowed');
require_once __DIR__.'/autoload.php';
// Native Perfex deletes the module registry before invoking this file. Restore it until review completes.
if(!defined('PME_UNINSTALL_APPROVED')||PME_UNINSTALL_APPROVED!==true){
    $ci=get_instance();
    if(!$ci->db->where('module_name','multiempresa')->get(db_prefix().'modules')->row()){
        $ci->db->insert(db_prefix().'modules',['module_name'=>'multiempresa','installed_version'=>$module['installed_version']??\Multiempresa\Perfex\Version::current(),'active'=>0]);
    }
    redirect(admin_url('multiempresa/uninstall'));exit;
}
$app=\Multiempresa\Application\App::environment();
\Multiempresa\Perfex\FrontController::remove($app);
$table=\Multiempresa\Domain\Id::identifier($app->config['native_prefix'].'staff');
foreach($app->db->all('SELECT u.id,u.native_staff_id FROM pme_users u JOIN pme_user_preferences p ON p.user_id=u.id WHERE p.principal_access=0 AND u.platform_admin=0') as $u){$q=$app->db->pdo->prepare('UPDATE `'.$table.'` SET active=0 WHERE staffid=? AND admin=0');$q->execute([$u['native_staff_id']]);if($q->rowCount()){(new \Multiempresa\Application\StaffAccess($app))->savePreference($u['id'],['disabled_on_uninstall'=>1]);}}

