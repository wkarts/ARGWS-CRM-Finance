<?php
defined('BASEPATH') or exit('No direct script access allowed');
require_once __DIR__.'/../autoload.php';
require_once __DIR__.'/../helpers/multiempresa_helper.php';
class Multiempresa extends AdminController
{
    public function __construct() {
        parent::__construct();
        if (defined('PME_CONTEXT_READY')) { redirect(multiempresa_app()->config['native_url'].'admin/multiempresa'); }
        if (!$this->app_modules->is_active('multiempresa') && !in_array($this->router->fetch_method(),['uninstall','uninstall_plan','uninstall_confirm','uninstall_step','uninstall_finish'],true)) { show_404(); }
    }
    public function index() {
        if (!is_admin() && !staff_can('view','multiempresa')) { access_denied('multiempresa'); }
        $app=multiempresa_app();
        $actor=$app->auth->syncNative((int)get_staff_user_id());
        $panel=new \Multiempresa\Http\Panel($app,$actor);
        $notice=''; $secret=null;
        if ($this->input->method()==='post') {
            $action=(string)$this->input->post('action');
            $cap=in_array($action,['organization','company'],true)?'create':'edit';
            if (!is_admin() && !staff_can($cap,'multiempresa')) { access_denied('multiempresa'); }
            try { [$notice,$secret]=$panel->action($action); }
            catch (\Multiempresa\Domain\Failure $e) { $notice=$e->getMessage(); }
            catch (Throwable $e) { $notice='A operação não foi concluída. Confira o estado da empresa, as etapas realizadas e os registros antes de retomar.'; log_message('error','Multiempresa action '.preg_replace('/[^a-z_]/','',$action).': '.get_class($e)); }
        }
        $this->load->view('multiempresa/manage',['title'=>'Multiempresa','panel'=>$panel,'notice'=>$notice,'secret'=>$secret]);
    }
    public function authorize() {
        try {
            $app=multiempresa_app(); $actor=$app->auth->syncNative((int)get_staff_user_id());
            $company=(string)$this->input->get('company');
            $code=$app->auth->issueTicket($actor,$company,(string)$this->input->get('challenge'));
            $this->output->set_header('Referrer-Policy: no-referrer');
            redirect($app->catalog->url($app->catalog->company($company)).'__pme/callback?code='.rawurlencode($code));
        } catch (\Multiempresa\Domain\Failure $e) { show_error(html_escape($e->getMessage()),$e->status); }
    }

    public function companies() {
        $this->jsonAction(function($app,$actor){
            $manager=new \Multiempresa\Application\CompanyManager($app);
            return $manager->grid($actor,$this->input->get() ?: []);
        });
    }
    public function uninstall() {
        if(!is_admin()){access_denied('multiempresa');}
        $app=multiempresa_app();$actor=$app->auth->syncNative((int)get_staff_user_id());
        $this->load->view('multiempresa/uninstall',['title'=>'Remover Multiempresa','execution'=>(new \Multiempresa\Application\Uninstallation($app))->latest($actor)]);
    }
    public function uninstall_plan() {
        $this->jsonAction(fn($app,$actor)=>(new \Multiempresa\Application\Uninstallation($app))->prepare($actor,(string)$this->input->post('mode')),'delete',true);
    }
    public function uninstall_confirm() {
        $this->jsonAction(fn($app,$actor)=>(new \Multiempresa\Application\Uninstallation($app))->confirm($actor,(string)$this->input->post('id'),(string)$this->input->post('confirmation'),(bool)$this->input->post('accepted')),'delete',true);
    }
    public function uninstall_step() {
        $this->jsonAction(fn($app,$actor)=>(new \Multiempresa\Application\Uninstallation($app))->advance($actor,(string)$this->input->post('id')),'delete',true);
    }
    public function uninstall_finish() {
        if(!is_admin()){access_denied('multiempresa');}if($this->input->method()!=='post'){show_error('Use POST.',405);}
        try{$app=multiempresa_app();$actor=$app->auth->syncNative((int)get_staff_user_id());$service=new \Multiempresa\Application\Uninstallation($app);$id=(string)$this->input->post('id');$service->assertReady($actor,$id);define('PME_UNINSTALL_APPROVED',true);$this->session->unset_userdata('multiempresa_active_company');
            $path=dirname(__DIR__);$version=\Multiempresa\Perfex\Version::current();class_exists(\Multiempresa\Domain\Failure::class);class_exists(\Multiempresa\Application\ExecutionLog::class);class_exists(\Multiempresa\Infrastructure\Schema::class);$removed=$this->app_modules->uninstall('multiempresa');
            if(!$removed||is_dir($path)){if(!$this->db->where('module_name','multiempresa')->get(db_prefix().'modules')->row()){$this->db->insert(db_prefix().'modules',['module_name'=>'multiempresa','installed_version'=>$version,'active'=>0]);}throw new \Multiempresa\Domain\Failure('uninstall_files','Os recursos confirmados foram processados, mas o servidor não concluiu a remoção dos arquivos do módulo. Reinstale o pacote para retomar a finalização.',409);}
            $service->finish($actor,$id);set_alert('success','Multiempresa removido conforme o escopo confirmado. Instalação principal preservada.');redirect($app->config['native_url'].'admin/modules');}
        catch(\Multiempresa\Domain\Failure $error){show_error(html_escape($error->getMessage()),$error->status);}
    }
    public function installation() {
        $this->jsonAction(function($app,$actor){if(empty($actor['platform_admin'])){throw new \Multiempresa\Domain\Failure('platform_admin','Acesso restrito ao administrador global.',403);}return (new \Multiempresa\Application\Installation($app))->get();});
    }
    public function configure_installation() {
        $this->jsonAction(fn($app,$actor)=>(new \Multiempresa\Application\Installation($app))->configure($actor,(string)$this->input->post('organization_id')),'edit',true);
    }
    public function switch_company() {
        if($this->input->method()!=='post'){show_error('Use POST.',405);}
        try{$app=multiempresa_app();$actor=$app->auth->syncNative((int)get_staff_user_id());$company=(new \Multiempresa\Application\Installation($app))->switchTarget($actor,(string)$this->input->post('company_id'));$this->session->set_userdata('multiempresa_active_company',$company['id']);$this->output->set_header('Cache-Control: no-store, private');redirect($app->catalog->url($company).'__pme/start');}
        catch(\Multiempresa\Domain\Failure $e){show_error(html_escape($e->getMessage()),$e->status);}
    }
    public function return_to_installation() {
        try{$app=multiempresa_app();$actor=$app->auth->syncNative((int)get_staff_user_id());(new \Multiempresa\Application\Installation($app))->returnToNative($actor,(string)$this->input->get('request'));$this->session->unset_userdata('multiempresa_active_company');$this->output->set_header('Cache-Control: no-store, private');$this->output->set_header('Referrer-Policy: no-referrer');redirect((new \Multiempresa\Application\CompanyRoutes($app))->url(\Multiempresa\Application\Installation::ID).'admin');}
        catch(\Multiempresa\Domain\Failure $e){show_error(html_escape($e->getMessage()),$e->status);}
    }
    public function company() {
        $this->jsonAction(fn($app,$actor)=>(new \Multiempresa\Application\CompanyManager($app))->detail($actor,(string)$this->input->get('id')));
    }
    public function staff_choices() {
        $this->jsonAction(fn($app,$actor)=>(new \Multiempresa\Application\CompanyManager($app))->staffChoices($actor,(string)$this->input->get('company_id'),(string)$this->input->get('search')),'edit');
    }
    public function provision() {
        $this->jsonAction(fn($app,$actor)=>(new \Multiempresa\Application\Onboarding($app))->status($actor,(string)$this->input->get('id')));
    }
    public function company_action() {
        $action=(string)$this->input->post('operation');
        $cap=$action==='advance'?'view':(in_array($action,['check','begin','draft','start_draft'],true)?'create':($action==='remove'?'delete':'edit'));
        $this->jsonAction(function($app,$actor)use($action){
            $p=$this->input->post() ?: []; $id=(string)($p['company_id']??'');
            $manager=new \Multiempresa\Application\CompanyManager($app);
            $wizard=new \Multiempresa\Application\Onboarding($app);
            if($action==='check'){return $wizard->check($actor,$p);}
            if($action==='begin'){return $wizard->begin($actor,$p);}
            if($action==='draft'){return $wizard->begin($actor,array_replace($p,['save_only'=>true]));}
            if($action==='start_draft'){return $wizard->startDraft($actor,$p);}
            if($action==='advance'){if(!is_admin()&&!staff_can('create','multiempresa')&&!staff_can('edit','multiempresa')){throw new \Multiempresa\Domain\Failure('permission','Sem permissão para executar o provisionamento.',403);}return $wizard->advance($actor,(string)($p['id']??''));}
            if($action==='save'){$manager->save($actor,$id,$p);return ['message'=>'Configurações salvas.','company'=>$manager->detail($actor,$id)];}
            if($action==='remove'){throw new \Multiempresa\Domain\Failure('impact_required','Gere e confirme o plano de exclusão antes de remover a empresa.',409);}
            if($action==='backup'){
                $lock=new \Multiempresa\Infrastructure\AdvisoryLock($app,'updates');
                $company=$manager->managed($actor,$id);$manager->assertEditable($company);
                $job=\Multiempresa\Domain\Id::new();
                $app->db->exec("INSERT INTO pme_jobs(id,type,target,actor_id,status,created_at) VALUES(?,'backup',?,?,'pending',?)",[$job,$id,$actor['id'],time()]);
                return ['message'=>'Backup agendado. Acompanhe em Atualizações e backups.','job_id'=>$job];
            }
            if($action==='module_available'){
                $company=$manager->managed($actor,$id);$manager->assertEditable($company);
                (new \Multiempresa\Application\Modules($app))->makeAvailable($id,(string)($p['module']??''),!empty($p['reviewed']),true);
                return ['message'=>'Módulo disponível nesta empresa.','company'=>$manager->detail($actor,$id)];
            }
            if($action==='module'){
                $company=$manager->managed($actor,$id);$manager->assertEditable($company);
                $modules=new \Multiempresa\Application\Modules($app);$name=(string)($p['module']??'');
                if(!empty($p['enabled'])){$modules->approve($id,$name,!empty($p['reviewed']));}
                else{$modules->disable($id,$name);}
                return ['message'=>'Módulo atualizado nesta empresa.','company'=>$manager->detail($actor,$id)];
            }
            throw new \Multiempresa\Domain\Failure('action','Operação desconhecida.');
        },$cap,true);
    }
    public function global_settings() {
        $this->jsonAction(function($app,$actor){if(empty($actor['platform_admin'])){throw new \Multiempresa\Domain\Failure('platform_admin','Somente o administrador global pode consultar integrações.',403);}return (new \Multiempresa\Application\GlobalSettings($app))->get(true);});
    }
    public function save_global_settings() {
        $this->jsonAction(function($app,$actor){$input=json_decode((string)$this->input->post('configuration'),true,32,JSON_THROW_ON_ERROR);return (new \Multiempresa\Application\GlobalSettings($app))->save($actor,$input);},'edit',true);
    }
    public function test_cloudflare() {
        $this->jsonAction(function($app,$actor){if(empty($actor['platform_admin'])){throw new \Multiempresa\Domain\Failure('platform_admin','Somente o administrador global pode testar esta conexão.',403);}$input=$this->input->post('dns')??[];if(!is_array($input)){throw new \Multiempresa\Domain\Failure('cloudflare_configuration','Campos de conexão inválidos.');}$cfg=(new \Multiempresa\Application\GlobalSettings($app))->dnsForTest($input);return (new \Multiempresa\Integration\Cloudflare($app))->diagnose($cfg);},'edit',true);
    }
    public function test_global_database() {
        $this->jsonAction(function($app,$actor){if(empty($actor['platform_admin'])){throw new \Multiempresa\Domain\Failure('platform_admin','Somente o administrador global pode testar esta conexão.',403);}return (new \Multiempresa\Application\DatabaseProvisioning($app))->check((new \Multiempresa\Application\GlobalSettings($app))->get()['database']);},'edit',true);
    }
    public function lookup() {
        $this->jsonAction(function($app,$actor){if(!is_admin()&&!staff_can('create','multiempresa')&&!staff_can('edit','multiempresa')){throw new \Multiempresa\Domain\Failure('permission','Sem permissão para consultar dados cadastrais.',403);}$app->limits->hit('lookup:'.$actor['id'],20,60);return (new \Multiempresa\Application\Lookups($app))->lookup($actor,(string)$this->input->get('type'),(string)$this->input->get('value'));});
    }
    public function organizations() {
        $this->jsonAction(fn($app,$actor)=>(new \Multiempresa\Application\Organizations($app))->grid($actor,$this->input->get()?:[]));
    }
    public function organization() {
        $this->jsonAction(fn($app,$actor)=>(new \Multiempresa\Application\Organizations($app))->detail($actor,(string)$this->input->get('id')));
    }
    public function save_organization() {
        $cap=$this->input->post('id')?'edit':'create';$this->jsonAction(fn($app,$actor)=>(new \Multiempresa\Application\Organizations($app))->save($actor,$this->input->post()?:[]),$cap,true);
    }
    public function organization_entry() {
        try{$app=multiempresa_app();$actor=$app->auth->syncNative((int)get_staff_user_id());$org=(string)$this->input->get('id');$companies=$app->catalog->accessible($actor['id'],$org);if(!$companies){throw new \Multiempresa\Domain\Failure('membership_required','Você não possui acesso a empresas ativas desta organização.',403);}redirect($app->catalog->url($companies[0]).'__pme/start');}
        catch(\Multiempresa\Domain\Failure $e){show_error(html_escape($e->getMessage()),$e->status);}
    }
    public function impact_plan() {
        $op=(string)$this->input->post('operation');$this->jsonAction(fn($app,$actor)=>(new \Multiempresa\Application\ImpactPlans($app))->create($actor,$op,(string)$this->input->post('target'),$this->input->post()?:[]),str_starts_with($op,'delete_')?'delete':'edit',true);
    }
    public function confirm_impact() {
        $id=(string)$this->input->post('plan_id');$plan=multiempresa_app()->db->one('SELECT operation FROM pme_plans WHERE id=?',[$id]);
        $this->jsonAction(fn($app,$actor)=>(new \Multiempresa\Application\ImpactPlans($app))->confirm($actor,$id,(string)$this->input->post('confirmation'),(bool)$this->input->post('accepted')),str_starts_with($plan['operation']??'','delete_')?'delete':'edit',true);
    }
    public function certificate() {
        $company=(string)$this->input->post('company_id');$row=multiempresa_app()->db->one('SELECT schema_version FROM pme_companies WHERE id=?',[$company]);
        $this->jsonAction(function($app,$actor)use($company){$service=new \Multiempresa\Application\Certificates($app);if($this->input->post('remove')){$service->remove($actor,$company);return ['message'=>'Certificado removido.'];}$file=$_FILES['certificate']??null;if(!$file||$file['error']!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name'])||$file['size']>4194304){throw new \Multiempresa\Domain\Failure('certificate_upload','Falha no envio do certificado. Limite: 4 MB.');}return $service->import($actor,$company,$file['name'],file_get_contents($file['tmp_name']),(string)$this->input->post('certificate_password'));},$row&&(int)$row['schema_version']===0?'create':'edit',true);
    }
    public function logs() {
        $this->jsonAction(fn($app,$actor)=>(new \Multiempresa\Application\ExecutionLog($app))->query($actor,$this->input->get()?:[]));
    }
    public function download_logs() {
        if(!is_admin()&&!staff_can('view','multiempresa')){access_denied('multiempresa');} $app=multiempresa_app();$actor=$app->auth->syncNative((int)get_staff_user_id());$service=new \Multiempresa\Application\ExecutionLog($app);$filters=$this->input->get()?:[];$filters['page']=1;$data=[];
        $this->output->set_header('Cache-Control: no-store')->set_header('Content-Disposition: attachment; filename="multiempresa-logs-'.gmdate('Ymd-His').'.jsonl"')->set_content_type('application/x-ndjson');
        $stream=tmpfile();if(!$stream){show_error('Não foi possível preparar o arquivo de logs.',503);return;}
        try{
            $app->db->transaction(function()use($service,$actor,&$filters,&$data,$stream){
                $data=$service->query($actor,$filters,true);
                do{foreach($data['rows'] as $r){$line=json_encode($r,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)."\n";if(fwrite($stream,$line)!==strlen($line)){throw new RuntimeException('Log export write failed');}}if($filters['page']>=$data['pages']){break;}$filters['page']++;$data=$service->query($actor,$filters,true);}while(true);
            });
            $app->audit->log('logs.downloaded',$actor['id'],null,['filters'=>array_intersect_key($filters,array_flip(['organization_id','company_id','execution_id','from','to','status']))]);
            rewind($stream);header('Cache-Control: no-store');header('Content-Type: application/x-ndjson; charset=utf-8');header('Content-Disposition: attachment; filename="multiempresa-logs-'.gmdate('Ymd-His').'.jsonl"');fpassthru($stream);
        }finally{fclose($stream);}exit;

    }
    private function jsonAction(callable $callback,string $cap='view',bool $post=false): void {
        $status=200;
        try {
            if(($post && $this->input->method()!=='post') || (!$post && $this->input->method()!=='get')){throw new \Multiempresa\Domain\Failure('method','Método não permitido.',405);}
            if(!is_admin() && (!staff_can('view','multiempresa') || !staff_can($cap,'multiempresa'))){throw new \Multiempresa\Domain\Failure('permission','Você não tem permissão para esta operação.',403);}
            $app=multiempresa_app();$actor=$app->auth->syncNative((int)get_staff_user_id());
            // Long jobs must not hold the native PHP session lock and block parallel navigation.
            if(session_status()===PHP_SESSION_ACTIVE){session_write_close();}
            $data=['ok'=>true,'data'=>$callback($app,$actor)];
        } catch(\Multiempresa\Domain\Failure $e){$status=$e->status;$data=['ok'=>false,'error'=>$e->reason,'message'=>$e->getMessage()];}
        catch(Throwable $e){if(isset($app,$actor)){try{(new \Multiempresa\Application\ExecutionLog($app))->record('administration.failed','failed',$actor['id'],null,null,null,\Multiempresa\Application\ExecutionLog::exception($e));}catch(Throwable){}}$status=503;$data=['ok'=>false,'error'=>'operation_failed','message'=>'Não foi possível concluir. Consulte o andamento antes de tentar novamente.'];log_message('error','Multiempresa companies: '.get_class($e));}
        $data['csrf']=['name'=>$this->security->get_csrf_token_name(),'hash'=>$this->security->get_csrf_hash()];
        $this->output->set_status_header($status)->set_header('Cache-Control: no-store')->set_content_type('application/json')->set_output(json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
