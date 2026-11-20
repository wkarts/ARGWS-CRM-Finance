<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
require_once dirname(__DIR__) . '/autoload.php';
$checks = 0;
function warningViewCheck(bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException('FALHOU: ' . $label); }
    ++$GLOBALS['checks'];
}
function nfse_nacional_can(string $capability): bool { return $capability === 'transmit'; }
function nfse_nacional_status_label(string $status): string { return $status; }
function admin_url(string $route = ''): string { return '/admin/' . ltrim($route, '/'); }
function init_head(): void { echo '<header data-fixture="header"></header>'; }
function init_tail(): void { echo '<footer data-fixture="footer"></footer>'; }
$instance = (object) ['security' => new class {
    public function get_csrf_token_name(): string { return 'csrf_token'; }
    public function get_csrf_hash(): string { return 'fixture'; }
}];
function &get_instance() { return $GLOBALS['instance']; }
function warningViewRender(?array $notice): string {
    $ibpt_expired_notice = $notice;
    $documents = [['status' => 'ready', 'id' => 1, 'client_id' => 8, 'client_name' => 'Empresa modelo',
        'invoice_id' => 18, 'environment' => '2', 'service_amount' => '252.00']];
    $filters = []; $stats = ['ready' => 1];
    ob_start();
    include dirname(__DIR__) . '/views/manage.php';
    return (string) ob_get_clean();
}
$visible = warningViewRender(['uf' => 'BA', 'version' => '26.2.B', 'valid_to' => '2026-10-31']);
warningViewCheck(str_contains($visible, 'data-nfse-ibpt-expired-notice'), 'tarja aparece com tabela vencida');
warningViewCheck(str_contains($visible, 'IBPT vencida') && str_contains($visible, '31/10/2026'), 'tarja usa período brasileiro');
warningViewCheck(str_contains($visible, 'UF BA') && str_contains($visible, '26.2.B'), 'metadados de origem preservados na tarja');
warningViewCheck(str_contains($visible, 'data-nfse-emission="1"'), 'botão Emitir NFS-e não desaparece com aviso');
warningViewCheck(!str_contains($visible, 'modal fade') && !str_contains($visible, 'data-dismiss="modal"'), 'tarja não é modal nem exige dispensa');
warningViewCheck(str_contains($visible, 'role="note"'), 'aviso é informativo e não bloqueante');
$hidden = warningViewRender(null);
warningViewCheck(!str_contains($hidden, 'data-nfse-ibpt-expired-notice'), 'aviso ausente quando desabilitado ou não vencido');
warningViewCheck(str_contains($hidden, 'data-nfse-emission="1"'), 'emissão permanece acessível sem aviso');
$escaped = warningViewRender(['uf'=>'BA','version'=>'"><script>alert(1)</script>','valid_to'=>'2026-10-31']);
warningViewCheck(!str_contains($escaped, '<script>alert(1)</script>') && str_contains($escaped, '&lt;script&gt;'), 'dados inesperados escapados corretamente');
warningViewCheck(str_contains($escaped, 'data-nfse-emission="1"'), 'dados inválidos do aviso não bloqueiam ações da listagem');
echo 'OK: ' . $checks . " verificações reais da view Documentos e tarja IBPT.\n";
