<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Version_045 extends App_module_migration
{
    public function up()
    {
        // Profiles use the existing JSON columns; legacy data is translated when read and saved.
    }
}
