<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Version_044 extends App_module_migration
{
    public function up()
    {
        // Adds only the module-owned invoice preference table; existing fiscal data is preserved.
        require dirname(__DIR__) . '/install.php';
    }
}
