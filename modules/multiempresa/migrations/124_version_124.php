<?php
defined('BASEPATH') or exit('No direct script access allowed');
require_once __DIR__.'/../autoload.php';
class Migration_Version_124 extends App_module_migration
{
    public function up() { \Multiempresa\Perfex\Installer::install(124); }
}
