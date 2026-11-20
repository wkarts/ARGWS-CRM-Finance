<?php
defined('BASEPATH') or exit('No direct script access allowed');
class Migration_Version_083 extends App_module_migration
{
    public function up()
    {
        require_once __DIR__ . '/../autoload.php';
        require_once __DIR__ . '/../helpers/nfse_nacional_helper.php';
        \NfseNacional\Upgrade083::run(nfse_nacional_model());
    }
}
