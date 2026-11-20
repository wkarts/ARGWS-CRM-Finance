<?php

defined('BASEPATH') or exit('No direct script access allowed');
class Worker extends App_Controller
{
    public function index()
    {
        if (PHP_SAPI !== 'cli' || empty($GLOBALS['pme_job'])) {
            show_404();
            return;
        }
        $job = $GLOBALS['pme_job'];
        $op = $job['operation'];
        if ($op === 'cron') {
            $this->load->model('cron_model');
            $this->cron_model->run();
        } elseif ($op === 'migrate') {
            $this->load->config('migration');
            $this->load->library('migration', ['migration_enabled' => true,'migration_type' => $this->config->item('migration_type'),'migration_table' => $this->config->item('migration_table'),'migration_auto_latest' => false,'migration_version' => $this->config->item('migration_version'),'migration_path' => $this->config->item('migration_path')]);
            if ($this->migration->current() === false) {
                throw new RuntimeException('Native migration failed');
            }
            $this->db->where('module_name','multiempresa')->delete(db_prefix().'modules');
        } elseif ($op === 'module_activate') {
            if(strtolower($job['module'])==='multiempresa'){throw new RuntimeException('Central module cannot be installed in tenants');}
            if(!$this->app_modules->is_active($job['module'])&&!$this->app_modules->activate($job['module'])){throw new RuntimeException('Module activation failed');}
            if($this->app_modules->is_database_upgrade_required($job['module'])){$this->updateModule($job['module']);}
        } elseif ($op === 'module_update') {
            $this->updateModule($job['module']);
        } elseif ($op === 'module_deactivate') {
            if (!$this->app_modules->deactivate($job['module'])) { throw new RuntimeException('Module deactivation failed'); }
        } elseif ($op === 'module_migrate') {
            $migration = new App_module_migration($job['module']);
            if ($migration->to_latest() === false) {
                throw new RuntimeException('Module migration failed');
            }
        }
        $GLOBALS['pme_job_result'] = ['ok' => true,'operation' => $op];
    }
    private function updateModule(string $name): void
    {
        if(strtolower($name)==='multiempresa'){throw new RuntimeException('Central module cannot be updated in a company');}
        $module=$this->app_modules->get($name);
        $native=$this->db->where('module_name',$name)->get(db_prefix().'modules')->row_array();
        if(!$module||!$native){throw new RuntimeException('Installed module not found');}
        $target=trim((string)$module['headers']['version']);$current=(string)$native['installed_version'];$active=(int)$native['active'];
        if(version_compare($current,$target,'>')){throw new RuntimeException('Automatic module downgrade refused');}
        if(version_compare($current,$target,'<')){
            include_once($module['init_file']);
            $migration=new App_module_migration($name);$files=$migration->find_migrations();
            $currentNumber=str_replace('.','',$current);$targetNumber=str_replace('.','',$target);
            if(!ctype_digit($currentNumber)||!ctype_digit($targetNumber)){throw new RuntimeException('Unsupported native module migration version');}
            $pending=array_filter(array_keys($files),static fn($n)=>(int)$n>(int)$currentNumber);
            if($pending){
                for($n=(int)$currentNumber+1;$n<=(int)$targetNumber;$n++){if(!isset($files[sprintf('%03d',$n)])&&!isset($files[$n])){throw new \Multiempresa\Domain\Failure('module_migration_gap','O pacote possui uma lacuna na sequência de migrações do módulo.');}}
                if($migration->to_latest()===false){throw new RuntimeException('Module migration failed');}
            }else{
                // A package containing only code/assets has no SQL migration to run.
                $this->db->where('module_name',$name)->update(db_prefix().'modules',['installed_version'=>$target]);
            }
        }
        $updated=$this->db->where('module_name',$name)->get(db_prefix().'modules')->row_array();
        if(!$updated||version_compare($updated['installed_version'],$target,'!=')||(int)$updated['active']!==$active){throw new RuntimeException('Module version/state verification failed');}
    }

}
