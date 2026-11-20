<?php
declare(strict_types=1);
namespace Multiempresa\Application;
use Multiempresa\Domain\{Failure,Id};

final class ExecutionLog
{
    public function __construct(private App $app) {}
    public static function sanitize(array $data): array
    {
        $out=[];foreach($data as $key=>$v){if(preg_match('/password|senha|token|secret|credential|authorization|certificate|pkcs|private.?key|encryption.?key|payload|connection|sql_text/i',(string)$key)){$out[$key]='[protegido]';continue;}$out[$key]=is_array($v)?self::sanitize($v):(is_scalar($v)||$v===null?$v:'[objeto]');}return $out;
    }
    public static function exception(\Throwable $e): array
    {
        $d=['exception'=>get_class($e),'reason'=>$e instanceof Failure?$e->reason:'operation_failed','file'=>basename($e->getFile()),'line'=>$e->getLine()];
        if($e instanceof Failure){$d['diagnosis']=$e->getMessage();if($e->diagnostic){$d['worker']=self::sanitize($e->diagnostic);}}
        if($e instanceof \RuntimeException && $d['file']==='nfse_nacional_helper.php' && $e->getMessage()==='O prefixo de banco da empresa é inválido.'){
            $d['reason']='module_prefix';$d['diagnosis']='O módulo NFS-e Nacional rejeitou o prefixo do banco. Em banco exclusivo sem prefixo, atualize o NFS-e Nacional para uma versão compatível e retome as etapas pendentes.';
        }
        if($e instanceof \PDOException){$d['sqlstate']=$e->errorInfo[0]??$e->getCode();$d['driver_code']=$e->errorInfo[1]??null;}
        if($e instanceof \mysqli_sql_exception){$d['sqlstate']=$e->getSqlState();$d['driver_code']=$e->getCode();}
        if(($d['driver_code']??null)==1146&&preg_match("/Table '[A-Za-z0-9_]+\.([A-Za-z0-9_]+)' doesn't exist/",$e->getMessage(),$match)){$d['missing_table']=$match[1];$d['diagnosis']='Tabela necessária não encontrada: '.$match[1].'. Revise o instalador e o prefixo do módulo indicado.';}
        $d['trace']=array_map(static fn($f)=>['class'=>$f['class']??'','function'=>$f['function']??'','file'=>basename($f['file']??''),'line'=>$f['line']??0],array_slice($e->getTrace(),0,10));return $d;
    }
    public function record(string $stage,string $status,?string $actor=null,?string $company=null,?string $org=null,?string $execution=null,array $details=[],int $duration=0): void
    {
        if($company&&!$org){$org=$this->app->db->one('SELECT organization_id FROM pme_companies WHERE id=?',[$company])['organization_id']??null;}
        $row=[Id::new(),$org,$company,$actor,$execution,$stage,$status,$duration,json_encode(self::sanitize($details),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),time()];
        $sig=$this->app->vault->mac(json_encode($row,JSON_THROW_ON_ERROR));$this->app->db->exec('INSERT INTO pme_execution_logs(id,organization_id,company_id,actor_id,execution_id,stage,status,duration_ms,details,created_at,signature) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[...$row,$sig]);
    }
    public function query(array $actor,array $filters,bool $download=false): array
    {
        $where=['1=1'];$args=[];
        if(empty($actor['platform_admin'])){$where[]="EXISTS(SELECT 1 FROM pme_org_members om WHERE om.organization_id=l.organization_id AND om.user_id=? AND om.role='admin')";$args[]=$actor['id'];$where[]='l.organization_id=?';$args[]=$this->app->catalog->organizationFor($actor['id'])??'';}
        foreach(['organization_id','company_id','execution_id','status'] as $field){if(!empty($filters[$field])){$where[]='l.'.$field.'=?';$args[]=(string)$filters[$field];}}
        foreach(['from'=>'>=','to'=>'<='] as $field=>$operator){if(!empty($filters[$field])){$ts=strtotime((string)$filters[$field].($field==='to'?' 23:59:59 UTC':' 00:00:00 UTC'));if($ts===false){throw new Failure('log_period','Período inválido.');}$where[]='l.created_at'.$operator.'?';$args[]=$ts;}}
        $condition=implode(' AND ',$where);$total=(int)$this->app->db->one('SELECT COUNT(*) AS total FROM pme_execution_logs l WHERE '.$condition,$args)['total'];
        $size=$download?1000:max(10,min(100,(int)($filters['size']??50)));$page=max(1,min(max(1,(int)ceil($total/$size)),(int)($filters['page']??1)));
        $rows=$this->app->db->all('SELECT l.*,o.name AS organization_name,c.name AS company_name,u.name AS actor_name FROM pme_execution_logs l LEFT JOIN pme_organizations o ON o.id=l.organization_id LEFT JOIN pme_companies c ON c.id=l.company_id LEFT JOIN pme_users u ON u.id=l.actor_id WHERE '.$condition.' ORDER BY l.created_at DESC,l.id DESC LIMIT '.$size.' OFFSET '.(($page-1)*$size),$args);
        foreach($rows as &$row){$row['details']=json_decode($row['details'],true,32,JSON_THROW_ON_ERROR);}unset($row);
        return ['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/$size)),'size'=>$size];
    }
}
