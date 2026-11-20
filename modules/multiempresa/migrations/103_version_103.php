<?php
defined('BASEPATH') or exit('No direct script access allowed');
class Migration_Version_103 extends App_module_migration
{
    public function up() {
        require_once __DIR__.'/../autoload.php';
        // Metadata is unchanged; retain idempotent installation and native upgrade tracking.
        \Multiempresa\Perfex\Installer::install(103);
    }
}
