<?php
defined('BASEPATH') or exit('No direct script access allowed');
class Migration_Version_114 extends App_module_migration
{
    public function up() {
        // No catalog DDL is needed. Adapter 4 rebuilds legacy private module copies on their next use.
    }
}
