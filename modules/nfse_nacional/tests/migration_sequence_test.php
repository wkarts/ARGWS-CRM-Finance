<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);

$checks = 0; $migrationNativeCalls = [];
function migrationSequenceCheck(bool $condition, string $label): void {
    if (!$condition) { throw new RuntimeException('FALHOU: ' . $label); } ++$GLOBALS['checks'];
}
function migrationUnexpectedWrite(string $operation): never {
    $GLOBALS['migrationNativeCalls'][] = $operation;
    throw new RuntimeException('Migração de continuidade tentou acessar/gravar a aplicação: ' . $operation);
}
final class MigrationNoWriteDatabase {
    public array $fiscalRows = [['id' => 1, 'response_enc' => 'ORIGINAL-ENCRYPTED-XML', 'dps_number' => '481', 'status' => 'authorized']];
    public function __call(string $method, array $args) { migrationUnexpectedWrite('db.' . $method); }
}
final class MigrationNoWriteService {
    public function __call(string $method, array $args) { migrationUnexpectedWrite('native.' . $method); }
}
$migrationCi = (object) ['db' => new MigrationNoWriteDatabase(), 'dbforge' => new MigrationNoWriteService(), 'load' => new MigrationNoWriteService()];
function &get_instance() { return $GLOBALS['migrationCi']; }
function db_prefix(): string { return 'migration_sequence_offline_'; }
function update_option(...$args) { migrationUnexpectedWrite('update_option'); }
function add_option(...$args) { migrationUnexpectedWrite('add_option'); }
function delete_option(...$args) { migrationUnexpectedWrite('delete_option'); }
class App_module_migration {
    public function __get(string $name) { return get_instance()->{$name}; }
}

/** Same filename discovery convention as CI/Perfex sequential module migrations. */
function migrationCatalog(string $directory): array {
    $catalog = [];
    foreach (glob($directory . '/*_*.php') as $file) {
        $name = basename($file, '.php');
        if (!preg_match('/^(\d{3})_(\w+)$/D', $name, $match)) { continue; }
        $number = (int) $match[1];
        if (isset($catalog[$number])) { throw new RuntimeException('Versão de migração duplicada: ' . $match[1]); }
        $catalog[$number] = ['file' => $file, 'class' => 'Migration_' . ucfirst(strtolower($match[2]))];
    }
    ksort($catalog, SORT_NUMERIC); return $catalog;
}

/** Verify the actual update path, including the step immediately after the installed version. */
function migrationPendingPath(array $catalog, int $installed, int $target): array {
    if (!isset($catalog[$target])) { throw new RuntimeException('Migração final não encontrada: ' . sprintf('%03d', $target)); }
    $path = [];
    for ($number = $installed + 1; $number <= $target; ++$number) {
        if (!isset($catalog[$number])) { throw new RuntimeException('Lacuna na sequência de migrações: ' . sprintf('%03d', $number)); }
        $path[] = $number;
    }
    return $path;
}

$module = dirname(__DIR__); $catalog = migrationCatalog($module . '/migrations');
$entrypoint = file_get_contents($module . '/nfse_nacional.php');
migrationSequenceCheck((bool) preg_match('/^Version:\s*(\d+)\.(\d+)\.(\d+)\s*$/m', $entrypoint, $version), 'module declares a semantic version for the migration target');
$target = (int) ($version[1] . $version[2] . $version[3]);
migrationSequenceCheck($target >= 70 && isset($catalog[$target]) && max(array_keys($catalog)) === $target, 'published module version matches the latest discoverable migration');
migrationSequenceCheck((bool) preg_match('/define\(\s*[\'"]NFSE_NACIONAL_VERSION[\'"]\s*,\s*[\'"]' . preg_quote($version[1] . '.' . $version[2] . '.' . $version[3], '/') . '[\'"]\s*\)/', $entrypoint), 'runtime version and module header agree');

foreach ($catalog as $number => $migration) {
    require_once $migration['file'];
    $class = $migration['class'];
    migrationSequenceCheck(class_exists($class, false) && is_subclass_of($class, App_module_migration::class)
        && method_exists($class, 'up') && (new ReflectionMethod($class, 'up'))->isPublic(),
        'Perfex-discovered migration has the matching public class/up method: ' . sprintf('%03d', $number));
}

foreach ([20, 30, 31, 49, 50, 60] as $installed) {
    $path = migrationPendingPath($catalog, $installed, $target);
    migrationSequenceCheck($path === range($installed + 1, $target) && $path[0] === $installed + 1 && end($path) === $target,
        'continuous upgrade path from ' . sprintf('%03d', $installed) . ' to ' . sprintf('%03d', $target));
}
migrationSequenceCheck(migrationPendingPath($catalog, $target, $target) === [], 'already-current version schedules no migration');

// These release gaps now contain compatibility steps only; execute the real
// methods twice with a database/options sentinel to catch accidental writes.
$bridges = [...range(21, 29), ...range(32, 39), ...range(51, 59), ...range(61, 69)];
$initialRows = $migrationCi->db->fiscalRows;
foreach ($bridges as $number) {
    migrationSequenceCheck(isset($catalog[$number]), 'continuity migration exists: ' . sprintf('%03d', $number));
    $class = $catalog[$number]['class']; $migration = new $class();
    ob_start();
    try { $migration->up(); $migration->up(); $output = ob_get_contents(); }
    finally { ob_end_clean(); }
    migrationSequenceCheck($output === '' && $migrationNativeCalls === [] && $migrationCi->db->fiscalRows === $initialRows,
        'continuity up() is repeatable, silent and does not touch data/options: ' . sprintf('%03d', $number));
}

// Prove that this gate detects the reported 050→060 failure without modifying
// any production file: remove a bridge from the in-memory discovery catalog.
foreach ([21 => 20, 32 => 31, 51 => 50, 59 => 50, 61 => 60, 69 => 60] as $missing => $installed) {
    $broken = $catalog; unset($broken[$missing]); $detected = false;
    try { migrationPendingPath($broken, $installed, $target); }
    catch (RuntimeException $error) { $detected = str_contains($error->getMessage(), sprintf('%03d', $missing)); }
    migrationSequenceCheck($detected, 'missing intermediate version blocks the update before migration execution: ' . sprintf('%03d', $missing));
}
$oldGap = $catalog; unset($oldGap[59]);
migrationSequenceCheck(migrationPendingPath($oldGap, 60, $target) === range(61, $target), 'a missing earlier version does not rerun or invalidate an already-applied schema');

echo 'OK: ' . $checks . ' verificações de sequência contínua; ' . count($bridges)
    . " migrações de continuidade executadas duas vezes sem escrita. Sem banco ou aplicação externos.\n";
