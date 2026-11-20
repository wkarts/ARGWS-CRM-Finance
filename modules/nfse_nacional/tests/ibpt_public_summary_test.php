<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
require dirname(__DIR__) . '/autoload.php';
class App_Model { public $db = null; }
require dirname(__DIR__) . '/models/Nfse_nacional_model.php';

use NfseNacional\ApiClient;
use NfseNacional\Certificate;
use NfseNacional\Config;
use NfseNacional\Crypto;
use NfseNacional\DpsBuilder;
use NfseNacional\DraftTax;
use NfseNacional\Service;
use NfseNacional\XmlSigner;

function nfse_nacional_can(string $capability): bool { return false; }
function nfse_nacional_scope(): string { return hash('sha256', 'ibpt-public-summary'); }
function db_prefix(): string { return 'ibpt_summary_'; }
function is_staff_logged_in(): bool { return true; }
function get_staff_user_id(): int { return 7; }
function get_option(string $name): string { return $name === 'company_vat' ? '11222333000181' : ''; }
$checks = 0;
function summaryCheck(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException('FALHOU: ' . $message); } ++$GLOBALS['checks']; }
function summaryReject(callable $operation, string $message): void {
    try { $operation(); } catch (Throwable $error) { summaryCheck(true, $message); return; }
    throw new RuntimeException('FALHOU: ' . $message);
}

final class IbptSummaryModel extends Nfse_nacional_model
{
    public array $doc; public array $config; public array $events = []; public array $changes = []; public int $reservations = 0;
    private Crypto $cipher; private array $settingsRow;
    public function __construct(array $draft, string $signed, string $pfx)
    {
        $this->cipher = new Crypto(str_repeat('only-tests-', 5), 'ibpt-summary');
        $this->config = array_replace_recursive(Config::defaults(), ['provider'=>$draft['provider'], 'environment'=>'2', 'use_local_certificate'=>'1']);
        $this->settingsRow = ['certificate_enc'=>$this->cipher->encrypt(self::json(['pfx'=>base64_encode($pfx),'password'=>'offline-test']))];
        $json = self::json($draft);
        $fingerprint = self::fingerprint($this->invoice(1));
        $this->doc = ['invoice_fingerprint'=>$fingerprint,'id'=>1,'invoice_id'=>1,'client_id'=>1,'environment'=>'2','status'=>'uncertain','attempts'=>1,
            'dps_id'=>DpsBuilder::identifier($draft['provider'],'1','1'),'dps_series'=>'1','dps_number'=>'1',
            'request_enc'=>$this->cipher->encrypt($signed),'response_enc'=>null,'draft_json'=>$json,'draft_hash'=>hash('sha256',$json),
            'access_key'=>null,'nfse_number'=>null,'fiscal_meta_json'=>'{}','service_amount'=>'252.00','send_after_authorized'=>0,'email_status'=>'pending'];
    }
    public function invoice(int $id): object { return (object) ['id'=>1,'clientid'=>1,'status'=>1,'currency'=>1,'subtotal'=>'252.00','discount_total'=>'0.00','total'=>'252.00','items'=>[]]; }
    public function assertInvoice(object $invoice): void { if ($invoice->status !== 1) { throw new RuntimeException('Fatura indisponível.'); } }
    public function crypto(): Crypto { return $this->cipher; }
    public function settings(): array { return $this->config; }
    public function settingsRow(): array { return $this->settingsRow; }
    public function document(int $id): array { if ($id !== 1) { throw new RuntimeException('Documento inexistente.'); } return $this->doc; }
    public function updateDocument(int $id,array $changes): void { $this->changes[]=$changes; $this->doc=array_replace($this->doc,$changes); }
    public function event(int $id,string $type,string $message): void { $this->events[]=[$type,$message]; }
    public function lock(string $name,callable $operation) { return $operation(); }
    public function reserve(array $document,array $draft,array $settings): array { ++$this->reservations; throw new RuntimeException('Recuperação não pode reservar uma nova DPS.'); }
}

final class IbptSummaryApi
{
    public array $calls = []; public array $posts = [];
    public function __construct(private array $response,private bool $found=true) {}
    public function findByDps(string $dps): ?array { $this->calls[]=['GET-DPS',$dps]; return $this->found ? $this->response : null; }
    public function fetch(string $key): array { $this->calls[]=['GET-KEY',$key]; return $this->response; }
    public function submit(string $xml): array { $this->calls[]=['POST',hash('sha256',$xml)]; $this->posts[]=$xml; return $this->response; }
}

function summaryResponse(string $signed, bool $duplicateIss=false): array
{
    $parsed=XmlSigner::loadSafe($signed); $xp=new DOMXPath($parsed); $xp->registerNamespace('n',XmlSigner::NFSE_NS);
    $dps=$xp->evaluate('string(/n:DPS/n:infDPS/@Id)'); $key=str_repeat('1',50);
    $xml='<?xml version="1.0" encoding="UTF-8"?><NFSe xmlns="http://www.sped.fazenda.gov.br/nfse"><infNFSe Id="NFS'.$key.'">'
        .'<nNFSe>123</nNFSe><cStat>100</cStat><dhProc>2026-10-30T12:00:00-03:00</dhProc><nDFSe>1</nDFSe>'
        .'<valores><vBC>252.00</vBC><pAliqAplic>2.00</pAliqAplic><vISSQN>5.04</vISSQN>'
        .($duplicateIss?'<vISSQN>5.04</vISSQN>':'').'</valores>'
        .preg_replace('/<\?xml[^>]*\?>\s*/','',$signed).'</infNFSe></NFSe>';
    return ['chaveAcesso'=>$key,'idDps'=>$dps,'tipoAmbiente'=>2,'nfseXmlGZipB64'=>base64_encode(gzencode($xml))];
}

$opensslConfig=tempnam(sys_get_temp_dir(),'nfse_ibpt_summary_');
file_put_contents($opensslConfig,"[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=TESTE OFFLINE:11222333000181\n");
try {
    $key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA,'config'=>$opensslConfig]);
    $csr=openssl_csr_new(['commonName'=>'TESTE OFFLINE:11222333000181'],$key,['config'=>$opensslConfig,'digest_alg'=>'sha256']);
    $certificate=openssl_csr_sign($csr,null,$key,1,['config'=>$opensslConfig,'digest_alg'=>'sha256']);
    $pfx=''; openssl_pkcs12_export($certificate,$pfx,$key,'offline-test');
} finally { unlink($opensslConfig); }
$snapshot=['uf'=>'BA','nbs'=>'101011000','description'=>'Serviço','nacionalfederal'=>'13.4500','estadual'=>'0.0000','municipal'=>'5.0000',
    'valid_from'=>'2026-09-20','valid_to'=>'2026-10-31','chave'=>'C44399','versao'=>'26.2.B','fonte'=>'IBPT/empresometro.com.br',
    'fetched_at'=>'2026-10-30T12:00:00Z','source_hash'=>hash('sha256','synthetic-fixture')];
$draft=['environment'=>'2','issued_at'=>'2026-10-30T12:00:00-03:00','competence'=>'2026-10-01',
    'provider'=>array_replace(Config::defaults()['provider'],['tax_id'=>'11222333000181','name'=>'Emitente de teste','state'=>'BA','municipality_code'=>'2928703','simple_status'=>'3','simple_apportionment'=>'1']),
    'recipient'=>['tax_id'=>'52998224725','name'=>'Tomador de teste','municipality_code'=>'2928703','zip'=>'44570000','street'=>'Rua Teste','number'=>'1','district'=>'Centro'],
    'service'=>['municipality_code'=>'2928703','national_code'=>'010101','municipal_code'=>'','nbs'=>'101011000','description'=>'Teste offline'],
    'values'=>array_replace(Config::defaults()['tax'],['service_amount'=>'252.00','unconditional_discount'=>'0.00','conditional_discount'=>'0.00','iss_rate'=>'3.00','simple_rate'=>'3.00','total_tax_mode'=>'ibpt']),
    'ibpt'=>$snapshot,'ibscbs'=>Config::defaults()['ibscbs']];
$draft=DraftTax::refresh($draft,static function (): array { throw new RuntimeException('Não deve consultar fonte de um snapshot original.'); });
$signed=XmlSigner::sign((new DpsBuilder())->build($draft,'1','1'),Certificate::fromPkcs12($pfx,'offline-test','11222333000181'));
$response=summaryResponse($signed); $officialXml=ApiClient::decodeXml($response);
$model=new IbptSummaryModel($draft,$signed,$pfx); $model->doc=array_replace($model->doc,['status'=>'authorized','access_key'=>$response['chaveAcesso'],'nfse_number'=>'123','response_enc'=>$model->crypto()->encrypt($officialXml)]);
$service=new Service($model);
$public=$service->publicDocument($model->doc);
summaryCheck($public['status']==='authorized' && $public['tax_summary']['iss']['official']===true && $public['tax_summary']['iss']['amount']==='5.04', 'resumo autorizado usa ISSQN oficial, não a prévia de 7,56');
summaryCheck($public['tax_summary']['ibpt']['snapshot']['chave']==='C44399' && $public['tax_summary']['ibpt_error']==='', 'consulta histórica conserva fonte válida na data original de emissão');
summaryCheck(!isset($public['response_enc'],$public['request_enc'],$public['draft_json']), 'resumo público não expõe envelopes protegidos');
$broken=$model->doc; $broken['draft_json']='{broken';
summaryCheck($service->fiscalStatus($broken)['is_authorized']===true, 'JSON auxiliar corrompido não invalida a evidência fiscal original');
$public=$service->publicDocument($broken);
summaryCheck($public['status']==='authorized' && $public['fiscal_status']['is_authorized']===true, 'falha no resumo opcional não impede apresentar autorização confirmada');
$duplicate=$model->doc; $duplicate['response_enc']=$model->crypto()->encrypt(ApiClient::decodeXml(summaryResponse($signed,true)));
summaryCheck($service->fiscalStatus($duplicate)['is_authorized']===true, 'fixture de ISSQN ambíguo mantém os identificadores fiscais conferíveis');
$public=$service->publicDocument($duplicate);
summaryCheck($public['status']==='authorized' && $public['tax_summary']['iss']===null, 'extração opcional ambígua fica indisponível sem fabricar imposto local ou rebaixar status');
$badKey=$model->doc; $badKey['access_key']=str_repeat('2',50);
summaryReject(fn()=>$service->publicDocument($badKey), 'checagem obrigatória da correlação fiscal continua bloqueando XML de outra chave');

// Exercise the real post-response commit path: an optional display failure used
// to escape authorize() and make submitPersisted() downgrade an authorized note.
foreach (['invalid-json','ambiguous-iss'] as $failure) {
    $postModel=new IbptSummaryModel($draft,$signed,$pfx);
    if ($failure==='invalid-json') { $postModel->doc['draft_json']='{broken'; }
    $postApi=new IbptSummaryApi(summaryResponse($signed,$failure==='ambiguous-iss'));
    $postService=new Service($postModel);
    $submit=new ReflectionMethod(Service::class,'submitPersisted');
    $result=$submit->invoke($postService,$postModel->doc,$signed,$postApi);
    summaryCheck($result['status']==='authorized' && $postModel->doc['status']==='authorized', 'autorização não é rebaixada por falha do resumo: '.$failure);
    summaryCheck(count($postApi->posts)===1 && $postApi->posts[0]===$signed && $postModel->reservations===0, 'falha visual não repete POST nem numeração: '.$failure);
    summaryCheck($postModel->crypto()->decrypt($postModel->doc['response_enc'])===ApiClient::decodeXml(summaryResponse($signed,$failure==='ambiguous-iss')), 'XML oficial permanece byte a byte após falha visual: '.$failure);
}

$lookupCalls=0;
$lookup=static function () use (&$lookupCalls): array { ++$lookupCalls; throw new RuntimeException('A tabela atual está indisponível.'); };
foreach (['dps','key','retry-found','retry-post'] as $operation) {
    $recoveryModel=new IbptSummaryModel($draft,$signed,$pfx);
    // Current defaults deliberately differ: recovery must use the original DPS.
    $recoveryModel->config['provider']['state']='SP';
    $recoveryModel->config['service']['nbs']='101099000';
    $recoveryApi=new IbptSummaryApi($response,$operation!=='retry-post');
    $recoveryService=new Service($recoveryModel,static fn()=>$recoveryApi,$lookup);
    $result=$operation==='dps'?$recoveryService->reconcile(1):($operation==='key'?$recoveryService->recoverByKey(1,$response['chaveAcesso']):$recoveryService->retrySame(1));
    summaryCheck($result['status']==='authorized' && $recoveryModel->doc['status']==='authorized', 'recuperação funciona com fonte hoje vencida: '.$operation);
    summaryCheck($lookupCalls===0 && $recoveryModel->reservations===0, 'recuperação não consulta IBPT nem reserva outra DPS: '.$operation);
    summaryCheck($recoveryModel->crypto()->decrypt($recoveryModel->doc['request_enc'])===$signed && $recoveryModel->doc['draft_json']===Nfse_nacional_model::json($draft), 'DPS e snapshot originais permanecem congelados: '.$operation);
    summaryCheck(count($recoveryApi->posts)===($operation==='retry-post'?1:0), 'somente reenvio explícito sem confirmação repete POST: '.$operation);
    if ($operation==='retry-post') { summaryCheck($recoveryApi->posts[0]===$signed, 'reenvio usa exatamente o XML assinado original'); }
    summaryCheck($result['tax_summary']['ibpt']['snapshot']['chave']==='C44399' && $result['tax_summary']['iss']['amount']==='5.04', 'histórico recuperado exibe fonte original e ISSQN oficial: '.$operation);
}
for ($i=0;$i<20;$i++) { $frozen=DraftTax::refresh($draft,$lookup); }
summaryCheck($lookupCalls===0 && $frozen===$draft, 'releitura de snapshot válido para sua emissão não faz nova chamada de tabela');
$newDraft=$draft; unset($newDraft['issued_at']);
$newDraft=DraftTax::refresh($newDraft,$lookup,'2026-11-11');
summaryCheck($lookupCalls===1 && ($newDraft['ibpt']['chave'] ?? '') === 'C44399' && empty($newDraft['ibpt_error']) && $newDraft['values']['total_federal'] === '33.89', 'nova emissão renova quando possível mas não bloqueia cálculo com tabela vencida');
echo 'OK: '.$checks." verificações de resumo e recuperação com IBPT.\n";
