'use strict';
const fs=require('fs'),path=require('path'),cp=require('child_process');
const {JSDOM}=require('jsdom');
const root=path.resolve(__dirname,'..');
const fixture=String.raw`
define('BASEPATH',__DIR__);require $argv[1].'/autoload.php';
function admin_url($p=''){return 'https://crm.example.test/admin/'.$p;}
function nfse_nacional_can($cap){return true;}
function &get_instance(){static $ci;return $ci??=(object)['security'=>new class{function get_csrf_token_name(){return 'csrf';}function get_csrf_hash(){return 'one';}}];}
$settings=\NfseNacional\Config::defaults();$settings['auth_unified']='1';
$settings['automations']['created']['mode']='selected';$settings['automations']['paid']['mode']='all';
for($i=0;$i<61;$i++)$settings['accounting_recipients'][]=['id'=>str_pad(dechex($i+1),16,'0',STR_PAD_LEFT),'name'=>'Contato '.$i,'email'=>'p'.$i.'@example.test'];
echo '<!doctype html><html><body><form>';require $argv[1].'/views/settings.php';echo '</form></body></html>';
`;
const html=cp.execFileSync(process.env.PHP_BIN||'php',['-r',fixture,root],{encoding:'utf8'});
const dom=new JSDOM(html,{url:'https://crm.example.test/admin/settings',runScripts:'outside-only'});
const w=dom.window,d=w.document;let n=0;function check(ok,msg){if(!ok)throw Error(msg);n++;}
w.HTMLElement.prototype.scrollIntoView=function(){};
w.eval(fs.readFileSync(path.join(root,'assets/nfse_nacional.js'),'utf8'));d.dispatchEvent(new w.Event('DOMContentLoaded'));
check(d.querySelectorAll('[name="settings[environment]"]').length===1,'one active environment selector');
check(d.querySelectorAll('[name="settings[auth_method]"]').length===1,'one authentication method selector');
check(d.querySelectorAll('input[type=file][name=certificate]').length===1&&!d.querySelector('[name=auth_certificate_1]')&&!d.querySelector('[name=auth_certificate_2]'),'one A1 upload for both environments');
check(d.querySelectorAll('[name=auth_username]').length===1&&d.querySelectorAll('[name=auth_password]').length===1,'one shared credential form');
check(!d.querySelector('[name="settings[automation][trigger]"]')&&d.querySelector('[name="settings[automations][paid][mode]"]')&&d.querySelector('[name="settings[automations][created][mode]"]'),'independent automation settings coexist');
check(!d.querySelector('#nfse-settings-certificate').textContent.includes('Multiempresa'),'certificate dependencies are not exposed');
const visible=()=>d.querySelectorAll('[data-nfse-email-body] tr:not([hidden])').length;
check(visible()===25,'first e-mail page has 25 recipients');d.querySelector('[data-email-page-next]').click();check(visible()===25&&d.querySelector('[data-email-page-status]').textContent.includes('Página 2'),'second page has next 25');d.querySelector('[data-email-page-next]').click();check(visible()===11,'last page preserves remaining 11');
let search=d.querySelector('[data-nfse-email-search]');search.value='p60@example.test';search.dispatchEvent(new w.Event('input',{bubbles:true}));check(visible()===1,'search spans all pages');
check(d.querySelector('[data-nfse-email-body] tr:not([hidden])').textContent.includes('Contato 60'),'search returns correct recipient beyond first page');
console.log(`OK: ${n} verificações DOM de configuração única, automações independentes e 61 e-mails paginados.`);w.close();
