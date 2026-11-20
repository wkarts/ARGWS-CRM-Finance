<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){exit(1);}
define('BASEPATH',__DIR__);
$GLOBALS['scopePrefix']='tbl';$GLOBALS['scopeCi']=(object)['db'=>(object)['database'=>'tenant_one','hostname'=>'localhost']];
function db_prefix():string{return $GLOBALS['scopePrefix'];}
function &get_instance(){return $GLOBALS['scopeCi'];}
require dirname(__DIR__).'/helpers/nfse_nacional_helper.php';
$n=0;function scopeCheck(bool $ok,string $label):void{global $n;if(!$ok){throw new RuntimeException($label);}$n++;}
function scopeReject(string $label):void{try{nfse_nacional_scope();}catch(RuntimeException $e){scopeCheck(true,$label);return;}throw new RuntimeException($label);}
$legacy=hash('sha256',json_encode(['database'=>'tenant_one','hostname'=>'localhost','prefix'=>'tbl','company'=>'standalone','organization'=>''],JSON_UNESCAPED_SLASHES));
scopeCheck(nfse_nacional_scope()===$legacy,'prefixed standalone retains its exact encryption scope');
$GLOBALS['scopePrefix']='';scopeCheck(strlen(nfse_nacional_scope())===64,'standalone empty prefix accepted without changing it');
define('PME_CONTEXT_READY',true);scopeReject('missing tenant context rejected');
$GLOBALS['pme_context']=(object)['companyId'=>str_repeat('a',32),'organizationId'=>str_repeat('c',32),'prefix'=>'other_','mode'=>'database'];scopeReject('mismatched tenant prefix rejected');
$GLOBALS['pme_context']->prefix='';unset($GLOBALS['pme_context']->mode);scopeCheck(strlen(nfse_nacional_scope())===64,'empty native prefix does not require provisioning mode metadata');
$GLOBALS['pme_context']->mode='prefix';scopeCheck(strlen(nfse_nacional_scope())===64,'native prefix is not inferred from optional provisioning mode');
$GLOBALS['pme_context']->mode='database';$scope=nfse_nacional_scope();scopeCheck(strlen($scope)===64,'trusted exclusive database accepts empty prefix');
foreach(['companyId'=>str_repeat('b',32),'organizationId'=>str_repeat('d',32)] as $field=>$value){$old=$GLOBALS['pme_context']->$field;$GLOBALS['pme_context']->$field=$value;scopeCheck(nfse_nacional_scope()!==$scope,'scope separates '.$field);$GLOBALS['pme_context']->$field=$old;}
foreach(['database'=>'tenant_two','hostname'=>'other-server'] as $field=>$value){$old=$GLOBALS['scopeCi']->db->$field;$GLOBALS['scopeCi']->db->$field=$value;scopeCheck(nfse_nacional_scope()!==$scope,'scope separates '.$field);$GLOBALS['scopeCi']->db->$field=$old;}
foreach(['../company','x`; DROP TABLE staff',str_repeat('x',41)] as $prefix){$GLOBALS['scopePrefix']=$prefix;$GLOBALS['pme_context']->prefix=$prefix;scopeReject('malformed prefix remains rejected');}
$GLOBALS['scopePrefix']='tenant_';$GLOBALS['pme_context']->prefix='tenant_';$GLOBALS['pme_context']->mode='prefix';scopeCheck(strlen(nfse_nacional_scope())===64,'valid shared prefix still works');
echo "RESULT $n tenant scope assertions passed\n";
