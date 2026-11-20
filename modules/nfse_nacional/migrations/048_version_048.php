<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Version_048 extends App_module_migration
{
    public function up()
    {
        // Operational logs are isolated protected files; fiscal tables stay unchanged.
    }
}
