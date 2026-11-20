<?php
declare(strict_types=1);
namespace Multiempresa\Application;

/** Central administrative privilege never becomes an inherited employee membership. */
final class UserPolicies
{
    public function __construct(private App $app) {}
    public function get(): array
    {
        $row=$this->app->db->one("SELECT value FROM pme_settings WHERE name='global_configuration'");
        $policies=array_replace(GlobalSettings::defaults()['users'],$row?($this->app->vault->open($row['value'])['users']??[]):[]);$policies['global_access']='explicit';return $policies;
    }
    /** Retained for extension compatibility: global management never implies a local employee. */
    public function globalAccess(string $user): bool { return false; }
}
