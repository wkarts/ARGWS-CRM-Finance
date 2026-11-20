<?php
defined('BASEPATH') or exit('No direct script access allowed');
// Mounted only in this company's private application/controllers/admin/Mods.php.
class Mods extends AdminController
{
    public function index()
    {
        if(!is_admin()){access_denied('modules');}
        $app=$GLOBALS['pme_app'];$ctx=$GLOBALS['pme_context'];
        $approved=$app->db->all('SELECT name,active FROM pme_modules WHERE company_id=? ORDER BY name',[$ctx->companyId]);
        $title=_l('modules');$modules=[];
        foreach($approved as $row){$native=$this->app_modules->get($row['name']);if($native){$modules[]=['name'=>$row['name'],'active'=>(bool)$row['active'],'native'=>$native];}}
        $this->load->vars(compact('title','modules','ctx'));
        $this->load->file(dirname(__DIR__).'/views/tenant_modules.php');
    }
}
