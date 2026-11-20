<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Version_043 extends App_module_migration
{
    public function up()
    {
        // Packaging timestamps and version metadata only; fiscal records are unchanged.
    }
}
