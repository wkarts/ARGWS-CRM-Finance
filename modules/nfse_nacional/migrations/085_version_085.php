<?php
defined('BASEPATH') or exit('No direct script access allowed');
class Migration_Version_085 extends App_module_migration
{
    public function up()
    {
        // UI and native capabilities only. No tax rates, staff/role grants, credentials,
        // encryption contexts, database schemas or fiscal records are rewritten.
    }
}
