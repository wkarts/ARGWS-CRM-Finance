<?php
defined('BASEPATH') or exit('No direct script access allowed');

/** Defaults stay in existing JSON records; catalog changes never rewrite fiscal snapshots. */
class Migration_Version_030 extends App_module_migration
{
    public function up()
    {
        require dirname(__DIR__) . '/install.php';
    }
}
