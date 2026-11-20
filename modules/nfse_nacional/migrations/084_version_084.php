<?php
defined('BASEPATH') or exit('No direct script access allowed');
class Migration_Version_084 extends App_module_migration
{
    public function up()
    {
        // Compatibility fix in the native prefix validation only.
        // No schema, settings, credentials, encryption contexts or data are changed.
    }
}
