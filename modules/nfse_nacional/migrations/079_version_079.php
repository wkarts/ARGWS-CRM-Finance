<?php

defined('BASEPATH') or exit('No direct script access allowed');

/** Distribution response compatibility fix; existing settings, documents and cursors stay intact. */
class Migration_Version_079 extends App_module_migration
{
    public function up()
    {
        // No database migration is necessary.
    }
}
