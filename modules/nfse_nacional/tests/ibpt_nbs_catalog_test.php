<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
require dirname(__DIR__) . '/autoload.php';

// Test-only polyfill: production requires mbstring; fixtures here are ASCII.
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $s, ?string $encoding = null): int { return strlen($s); }
}

use NfseNacional\IbptStore;
use NfseNacional\IbptTable;

$root = sys_get_temp_dir() . '/nfse-nbs-catalog-' . bin2hex(random_bytes(6));
$now = strtotime('2026-11-12T12:00:00Z');
$checks = 0;
function nbsCheck(bool $success, string $label): void {
    if (!$success) { throw new RuntimeException('FALHA: ' . $label); }
    ++$GLOBALS['checks'];
}
function nbsDelete(string $path): void {
    if (is_file($path) || is_link($path)) { @unlink($path); return; }
    foreach (glob($path.'/*') ?: [] as $child) { nbsDelete($child); }
    if (is_dir($path)) { @rmdir($path); }
}
function makeRow(string $uf, string $nbs, string $description, string $from, string $to): array {
    return ['uf' => $uf, 'nbs' => $nbs, 'description' => $description, 'chave' => 'C44399', 'versao' => '26.2.C',
        'fonte' => 'IBPT', 'nacionalfederal' => '13.4500', 'estadual' => '0.0000', 'municipal' => '5.0000',
        'valid_from' => $from, 'valid_to' => $to, 'fetched_at' => '2026-11-12T12:00:00Z', 'source_hash' => str_repeat('a', 64)];
}
function seed(IbptStore $store, string $uf, string $from, string $to): void {
    $store->transaction($uf, static function (array &$state) use ($uf, $from, $to): void {
        $state = ['version' => 1, 'uf' => $uf, 'rows' => [
            '115011000' => makeRow($uf, '115011000', 'Servico de tecnologia', $from, $to),
            '101011000' => makeRow($uf, '101011000', 'Construcao civil', $from, $to),
        ], 'acquisition' => 'upload'];
    });
}
try {
    $calls = 0;
    $storeA = new IbptStore($root, hash('sha256', 'empresa-A'), static fn() => $now);
    $storeB = new IbptStore($root, hash('sha256', 'empresa-B'), static fn() => $now);
    $tableA = new IbptTable($storeA, static function () use (&$calls): string { ++$calls; throw new RuntimeException('Network forbidden'); }, static fn() => $now);
    $tableB = new IbptTable($storeB, static function () use (&$calls): string { ++$calls; throw new RuntimeException('Network forbidden'); }, static fn() => $now);
    nbsCheck($tableA->services('BA') === [], 'Tabela ausente: nenhuma lista NBS');
    seed($storeA, 'BA', '2026-10-20', '2026-11-30');
    $list = $tableA->services('BA');
    nbsCheck(count($list) === 2, 'Tabela instalada disponibiliza os dois NBS');
    nbsCheck($list[0] === ['code' => '101011000', 'description' => 'Construcao civil'] && $list[1]['code'] === '115011000', 'Busca ordenada pelo NBS e com descricoes');
    nbsCheck($tableA->services('SP') === [], 'Tabela de outra UF nao e utilizada');
    nbsCheck($tableB->services('BA') === [], 'Tabela de outra empresa nao e compartilhada');
    nbsCheck($calls === 0, 'Listagem nao consulta API nem quando a tabela esta ausente');
    $expiredStore = new IbptStore($root, hash('sha256', 'empresa-vencida'), static fn() => $now);
    seed($expiredStore, 'BA', '2026-09-20', '2026-10-31');
    $expiredTable = new IbptTable($expiredStore, static fn() => throw new LogicException('No API'), static fn() => $now);
    nbsCheck(count($expiredTable->services('BA')) === 2, 'Tabela IBPT vencida continua pesquisavel');
    $futureStore = new IbptStore($root, hash('sha256', 'empresa-futura'), static fn() => $now);
    seed($futureStore, 'BA', '2026-12-01', '2026-12-31');
    $futureTable = new IbptTable($futureStore, static fn() => throw new LogicException('No API'), static fn() => $now);
    nbsCheck($futureTable->services('BA') === [], 'Tabela de vigencia futura nao produz lista de servicos');
    nbsCheck(count($tableA->services('BA')) === 2, 'Leitura repetida e local, sem alterar a tabela');
    echo "OK: {$checks} verificacoes de catalogo NBS IBPT local.\n";
} finally { nbsDelete($root); }
