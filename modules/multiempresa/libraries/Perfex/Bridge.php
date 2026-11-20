<?php

declare(strict_types=1);

namespace Multiempresa\Perfex;

use Multiempresa\Domain\Failure;
use Multiempresa\Infrastructure\Files;

final class Bridge
{
    public static function initialize(): void
    {
        $ci = get_instance();
        $ctx = $GLOBALS['pme_context'];
        $owner = $ci->db->where('id', 1)->get(db_prefix().'pme_owner')->row_array();
        if (!$owner || $owner['company_id'] !== $ctx->companyId) {
            throw new Failure('storage_owner', 'As tabelas não pertencem à empresa resolvida.', 503);
        }
        $ci->db->where('module_name','multiempresa')->delete(db_prefix().'modules');
        $actor = $ctx->principal;
        // Prevent a stale native session or remember-me cookie from substituting the central identity.
        $ci->session->unset_userdata(['staff_user_id','staff_logged_in']);
        if ($actor) {
            $staff = $ci->db->where('staffid', (int)$actor['staff_id'])->get(db_prefix().'staff')->row_array();
            $identity = $GLOBALS['pme_app']->db->one('SELECT email FROM pme_users WHERE id=? AND active=1', [$actor['user_id']]);
            if (!$staff || !(int)$staff['active']) {
                throw new Failure('staff_disabled', 'Funcionário local inativo.', 403);
            }
            if (!$identity || strtolower($staff['email']) !== $identity['email']) {
                throw new Failure('staff_mapping', 'O vínculo local mudou. Revise a identidade antes de acessar a empresa.', 403);
            }
            if (!empty($staff['two_factor_auth_enabled']) && empty($actor['mfa'])) {
                throw new Failure('mfa_required', 'Ative a autenticação em duas etapas na identidade global para entrar nesta empresa.', 403);
            }
            $ci->session->set_userdata(['staff_user_id' => (int)$staff['staffid'],'staff_logged_in' => true]);
        }
        // Native staff autologin is disabled; native client login remains available in this tenant.
        unset($_COOKIE['autologin'],$_COOKIE[APP_COOKIE_PREFIX.'autologin']);
        hooks()->add_action('pre_upgrade_database', static fn () => throw new Failure('central_update', 'Utilize a Central de Atualizações.', 409));
        foreach (['pre_activate_module','pre_deactivate_module','pre_uninstall_module'] as $hook) {
            hooks()->add_action($hook, static function () {
                if (empty($GLOBALS['pme_worker'])) {
                    throw new Failure('central_module', 'Gerencie os módulos na central.', 403);
                }
            });
        }
        StaffAccessBridge::register();
        hooks()->add_filter('admin_body_class',static fn($classes)=>array_merge($classes,['pme-tenant']));
        hooks()->add_action('app_admin_head',static function(){echo '<link rel="stylesheet" href="'.htmlspecialchars($GLOBALS['pme_app']->config['native_url'].'modules/multiempresa/assets/tenant.css?v='.Version::current(),ENT_QUOTES,'UTF-8').'">';});
        hooks()->add_action('before_render_aside_menu', [self::class,'selector']);
        hooks()->add_action('before_output_preview_image', [self::class,'guardPreview']);
        hooks()->add_action('before_output_preview_video', [self::class,'guardPreview']);
    }
    public static function guardPreview(): void
    {
        if (!is_staff_logged_in()) {
            show_404();
            exit;
        }
        $path = (string)get_instance()->input->get('path');
        if (!str_starts_with($path, 'uploads/') && !str_starts_with($path, 'media/')) {
            throw new Failure('preview_path', 'Pré-visualização indisponível.', 403);
        }
        $root = str_starts_with($path, 'uploads/') ? 'uploads' : 'media';
        Files::inside(FCPATH.$root, substr($path, strlen($root) + 1));
    }
    public static function selector(): void
    {
        $app = $GLOBALS['pme_app'];
        $ctx = $GLOBALS['pme_context'];
        if (!$ctx->principal) {
            return;
        }
        $company=$app->catalog->company($ctx->companyId);$companies=$app->catalog->accessible($ctx->principal['user_id'],$company['organization_id']);
        $installation=(new \Multiempresa\Application\Installation($app))->get();
        $actor=$app->auth->refresh($ctx->principal['user_id']);$service=new \Multiempresa\Application\Installation($app);
        if($service->sameOrganization($company['organization_id'],$installation)&&$service->canAccess($actor,$installation)){array_unshift($companies,['id'=>\Multiempresa\Application\Installation::ID,'name'=>$installation['name']]);}
        CompanySelector::render($app->config['native_url'].'modules/multiempresa/',$company['name'],$company['organization_name'],$ctx->baseUrl.'__pme/switch','csrf',$ctx->principal['csrf'],$companies,$ctx->companyId,false,!empty($actor['platform_admin'])?$app->config['native_url'].'admin/multiempresa':'');

    }
}
