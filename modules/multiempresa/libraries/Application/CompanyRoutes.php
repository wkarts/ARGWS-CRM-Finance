<?php
declare(strict_types=1);
namespace Multiempresa\Application;

use Multiempresa\Infrastructure\Database;

/** Public identifiers, never credentials. Access is checked separately on every request. */
final class CompanyRoutes
{
    public function __construct(private App $app) {}
    public function key(string $company): string
    {
        $row=$this->app->db->one('SELECT route_key FROM pme_routes WHERE target_id=?',[$company]);
        if($row){return $row['route_key'];}
        if($company!==Installation::ID){$this->app->catalog->company($company);}
        for($attempt=0;$attempt<5;$attempt++){
            $key=bin2hex(random_bytes(10));
            try{$this->app->db->exec('INSERT INTO pme_routes(route_key,target_id) VALUES(?,?)',[$key,$company]);return $key;}
            catch(\PDOException $e){if(!Database::duplicate($e)){throw $e;}$row=$this->app->db->one('SELECT route_key FROM pme_routes WHERE target_id=?',[$company]);if($row){return $row['route_key'];}}
        }
        throw new \RuntimeException('Não foi possível gerar o endereço da empresa.');
    }
    public function url(string $company): string
    {
        $key=$this->key($company);
        if(\Multiempresa\Perfex\FrontController::installed($this->app)){
            return rtrim($this->app->config['native_url'],'/').'/'.$key.'/';
        }
        // Automatic fallback for installations whose front controller is read-only.
        if($company===Installation::ID){return $this->app->config['native_url'];}
        return \Multiempresa\Http\Entry::url($this->app,$this->app->config['base_url'].'/'.$key.'/');
    }
    public function resolve(string $key): ?string
    {
        if(!preg_match('/\A[a-f0-9]{20}\z/',$key)){return null;}
        return $this->app->db->one('SELECT target_id FROM pme_routes WHERE route_key=?',[$key])['target_id']??null;
    }
}
