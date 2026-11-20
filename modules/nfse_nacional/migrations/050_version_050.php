<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Version_050 extends App_module_migration
{
    public function up()
    {
        // Establishment address uses the existing company-scoped settings JSON.
        // Existing documents, certificate and DPS sequences remain untouched.
    }
}
