<?php
defined('BASEPATH') or exit('No direct script access allowed');
class Migration_Version_089 extends App_module_migration
{
    public function up()
    {
        // Event compatibility and diagnostics only; preserve archive, cursor and credentials.
    }
}
