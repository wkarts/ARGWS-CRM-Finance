<?php
defined('BASEPATH') or exit('No direct script access allowed');
class Migration_Version_088 extends App_module_migration
{
    public function up()
    {
        // Queue resume fix only. No schema changes, cursor resets or credential rewrites.
    }
}
