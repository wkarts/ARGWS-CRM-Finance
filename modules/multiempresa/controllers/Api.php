<?php

defined('BASEPATH') or exit('No direct script access allowed');
class Api extends App_Controller
{
    public function read($resource, $id = null)
    {
        if (empty($GLOBALS['pme_api']) || !is_staff_logged_in()) {
            show_404();
            return;
        }
        $map = ['clients' => ['customers','clients','userid',['userid','company','vat','phonenumber','city','state','active']], 'invoices' => ['invoices','invoices','id',['id','clientid','number','prefix','date','duedate','currency','total','status']]];
        if (!isset($map[$resource])) {
            show_404();
            return;
        }[$permission,$table,$pk,$fields] = $map[$resource];
        if (!staff_can('view', $permission)) {
            \Multiempresa\Http\Response::json(['error' => 'local_permission'], 403);
        }
        $limit = max(1, min(100, (int)$this->input->get('limit') ?: 50));
        $offset = max(0, min(100000, (int)$this->input->get('offset')));
        $this->db->select(implode(',', $fields))->order_by($pk, 'asc')->limit($limit, $offset);
        if ($id !== null) {
            if (!ctype_digit((string)$id)) {
                show_404();
                return;
            }
            $this->db->where($pk, (int)$id);
        }
        $rows = $this->db->get(db_prefix().$table)->result_array();
        \Multiempresa\Http\Response::json(['company_id' => $GLOBALS['pme_context']->companyId,'data' => $rows,'limit' => $limit,'offset' => $offset]);
    }
}
