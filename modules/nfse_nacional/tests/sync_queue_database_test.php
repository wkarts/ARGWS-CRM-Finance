<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
if (!getenv('NFSE_TEST_MYSQL_PORT')) { echo "PENDENTE: teste requer MariaDB local descartável.\n"; exit(2); }
$argv[1] = 'native_empty';
function is_staff_logged_in() { return $GLOBALS['syncLoggedIn'] ?? true; }
function is_admin() { return $GLOBALS['syncAdmin'] ?? true; }
require __DIR__ . '/database_matrix_test.php';
require __DIR__ . '/fixtures/NationalNote.php';
$tax = '11222333000181';
$config=tempnam(sys_get_temp_dir(),'nfse_sync_cert');
file_put_contents($config,"[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=OFFLINE\n");
try {
    $key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA,'config'=>$config]);
    $csr=openssl_csr_new(['commonName'=>'OFFLINE:'.$tax],$key,['config'=>$config,'digest_alg'=>'sha256']);
    $cert=openssl_csr_sign($csr,null,$key,2,['config'=>$config,'digest_alg'=>'sha256']);
    $pfx='';openssl_pkcs12_export($cert,$pfx,$key,'offline');
} finally { unlink($config); }
$s=$model->settings();$s['auth_certificate_conflict']='0';$s['auth_method']='certificate';$s['environment']='2';
$model->saveSettings($s,json_encode(['pfx'=>base64_encode($pfx),'password'=>'offline']),[]);
$db->query('CREATE TABLE IF NOT EXISTS staff(staffid INT PRIMARY KEY,active TINYINT,admin TINYINT)');
$db->query('INSERT INTO staff VALUES(7,1,1) ON DUPLICATE KEY UPDATE active=1,admin=1');
$calls=[];$failure=true;
$factory=function($environment,$certificate)use(&$calls,&$failure,$tax){return new class($calls,$failure,$tax){
    public function __construct(private array &$calls,private bool &$failure,private string $tax){}
    public function distribute($nsu,$issuer){
        $this->calls[]=$nsu;
        if($nsu==='1'&&$this->failure){$this->failure=false;throw new NfseNacional\ApiException('Offline transport failure',0,true);}
        $n=(int)$nsu+1;
        if($n>3)return ['items'=>[],'next_nsu'=>$nsu,'has_more'=>false,'empty'=>true,'retry_after'=>3600];
        $year=[1=>2024,2=>2025,3=>2026][$n];
        $note=nationalNote($n,$this->tax,'11144477735','2',"$year-11-10","$year-11-10T12:00:00-03:00");
        return ['items'=>[$note],'next_nsu'=>(string)$n,'has_more'=>true,'empty'=>false,'retry_after'=>0];
    }
};};
$newQueue=fn()=>new NfseNacional\SyncQueue($model,new NfseNacional\NationalService($model,$factory));
$q=$newQueue();$q->start('2','period','2026-11-01','2026-11-30');$q->run(1);
verify($q->state('2')['status']==='pending' && $q->state('2')['processed']===1,'first 2024 page does not finish execution');
// Simulate a process dying after the archive/cursor committed but before job counters were saved.
$db->where('issuer_tax_id',$tax)->where('environment','2')->update('nfse_nacional_sync_runs',['processed'=>0,'batches'=>0,'status'=>'running']);
$q=$newQueue();verify($q->state('2')['processed']===1,'progress recovers committed items after interrupted job bookkeeping');
$q->run(1);verify($q->state('2')['status']==='waiting','transport failure recorded for automatic retry');
$repo=new NfseNacional\NationalRepository($model);verify($repo->state('2')['last_nsu']==='1','failed page preserves confirmed cursor');
$db->where('issuer_tax_id',$tax)->where('environment','2')->update('nfse_nacional_sync_runs',['next_run_at'=>gmdate('Y-m-d H:i:s',time()-1)]);
$q=$newQueue();$q->run(1);verify($repo->state('2')['last_nsu']==='2','new worker resumes after last confirmed page');
$q=$newQueue();$q->run(1);$q=$newQueue();$q->run(1);
$state=$q->state('2');verify($state['status']==='completed'&&(int)$state['processed']===3,'all pages completed automatically, including 2025 and 2026');
verify($calls===['0','1','1','2','3'],'resume does not restart history');
verify($db->count_all_results('nfse_nacional_national_documents')===3,'one archived document per key');
$list=$repo->list('issued',['environment'=>'2','date_from'=>'2026-11-01','date_to'=>'2026-11-30']);verify($list['total']===1,'period view returns requested 2026 document');
$xml=$repo->xml((int)$list['rows'][0]['id']);verify(str_contains($xml,'2026-11-10'),'retrieved XML remains available after resumed run');
$before=count($calls);$q->run(1);verify(count($calls)===$before,'completed run makes no extra network call');
// A new request observes the authority cooldown without pretending the first page completed the history.
$q->start('2','full');$q->run(1);verify($q->state('2')['status']==='waiting'&&count($calls)===$before,'national cooldown is persisted and respected');
echo "OK: fila durável, vários lotes 2024–2026, retomada após falha, filtro de período e XML; $n verificações acumuladas.\n";
