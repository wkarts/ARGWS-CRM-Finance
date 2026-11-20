<?php
defined('BASEPATH') or exit('No direct script access allowed');

/** Keep the sequential Perfex migration chain complete; no schema change. */
class Migration_Version_039 extends App_module_migration
{
    public function up()
    {
        // Intermediate version: data and schema remain unchanged.
    }
}
