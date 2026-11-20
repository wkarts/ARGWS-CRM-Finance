<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
require dirname(__DIR__) . '/autoload.php';

use NfseNacional\IbptCsvImport;
use NfseNacional\IbptStore;
use NfseNacional\IbptTable;

$checks = 0; $now = strtotime('2026-11-11T12:00:00Z'); $requests = 0;
$directory = sys_get_temp_dir() . '/nfse-ibpt-layout-' . bin2hex(random_bytes(8));
function layoutCheck(bool $value, string $message): void {
    if (!$value) { throw new RuntimeException('FALHOU: ' . $message); } ++$GLOBALS['checks'];
}
function layoutReject(callable $operation, string $fragment): void {
    try { $operation(); } catch (InvalidArgumentException $error) {
        layoutCheck(str_contains($error->getMessage(), $fragment), 'rejeição específica: ' . $fragment); return;
    }
    throw new RuntimeException('FALHOU: operação deveria rejeitar: ' . $fragment);
}
function layoutRow(array $values = []): array {
    return array_replace(['Codigo'=>'101011000', 'Excecao'=>'', 'Tipo'=>'nbs', 'UF'=>'BA', 'Tabela'=>'26.2.C',
        'Descricao'=>'Serviço sintético — construção; manutenção', 'AliquotaNacionalFederal'=>'13.45',
        'AliquotaImportadosFederal'=>'15.45', 'AliquotaEstadual'=>'0', 'AliquotaMunicipal'=>'5',
        'VigenciaInicio'=>'20/10/2026', 'VigenciaFim'=>'30/11/2026'], $values);
}
function layoutCsv(array $rows, string $delimiter = ';', ?array $headers = null): string {
    $headers ??= array_keys($rows[0]);
    $stream = fopen('php://temp', 'w+b'); fputcsv($stream, $headers, $delimiter, '"', '');
    foreach ($rows as $row) {
        $values = []; foreach ($headers as $header) { $values[] = $row[$header] ?? ''; }
        fputcsv($stream, $values, $delimiter, '"', '');
    }
    rewind($stream); $raw = stream_get_contents($stream); fclose($stream); return $raw;
}
function layoutClean(string $path): void {
    if (is_file($path) || is_link($path)) { unlink($path); return; }
    foreach (glob($path . '/*') ?: [] as $child) { layoutClean($child); }
    if (is_dir($path)) { rmdir($path); }
}
$clock = static fn(): int => $GLOBALS['now'];
$network = static function (): string { ++$GLOBALS['requests']; throw new RuntimeException('Sem rede neste teste.'); };
try {
    $scope = hash('sha256', 'layout-company-a');
    $table = new IbptTable(new IbptStore($directory, $scope, $clock), $network, $clock);
    $file = $directory . '/' . $scope . '/table-BA.php';
    $raw = layoutCsv([layoutRow(), layoutRow(['Codigo'=>'101012000', 'AliquotaMunicipal'=>'4,25']),
        layoutRow(['Codigo'=>'101099999', 'Tipo'=>'ncm']), layoutRow(['Codigo'=>'101099998', 'Tipo'=>'lc116'])]);
    layoutReject(fn() => $table->import('BA', $raw, 'ibpt-consulta (1).csv'), 'chave original');
    layoutCheck($table->status('BA')['status'] === 'missing', 'chave ausente não cria tabela parcial');
    $result = $table->import('BA', $raw, 'ibpt-consulta (1).csv', 'TESTE074');
    layoutCheck($result['status'] === 'current' && $result['service_count'] === 2, 'layout alternativo mantém somente registros NBS');
    layoutCheck($result['version'] === '26.2.C' && $result['key'] === 'TESTE074' && $result['source'] === 'IBPT', 'versão mapeada e chave sintética explícita; fonte sem domínio inventado');
    layoutCheck($result['valid_from'] === '2026-10-20' && $result['valid_to'] === '2026-11-30', 'vigência original preservada');
    $snapshot = $table->lookup('BA', '101012000');
    layoutCheck($snapshot['municipal'] === '4.2500' && $snapshot['estadual'] === '0.0000', 'percentuais mapeados sem aproximação');
    layoutCheck($snapshot['source_hash'] === hash('sha256', $raw), 'hash fiscal corresponde aos bytes enviados, não à normalização');
    layoutCheck($snapshot['description'] === layoutRow()['Descricao'], 'descrição acentuada e delimitador dentro do texto preservados');
    layoutCheck($requests === 0, 'importação e reutilização não usam rede');
    $state = json_decode(substr((string) file_get_contents($file), strlen("<?php exit; ?>\n")), true, 16, JSON_THROW_ON_ERROR);
    layoutCheck(!isset($state['rows']['101099999']) && !isset($state['rows']['101099998']), 'NCM e LC116 de nove dígitos não viram NBS');

    foreach ([';', ',', "\t", '|'] as $delimiter) {
        $input = layoutCsv([layoutRow(['AliquotaMunicipal'=>'4,25'])], $delimiter, array_reverse(array_keys(layoutRow())));
        $accepted = $table->import('BA', $input, 'consulta.csv', 'TESTE074');
        layoutCheck($accepted['status'] === 'current' && $accepted['service_count'] === 1, 'separador reconhecido com colunas reordenadas: ' . json_encode($delimiter));
        layoutCheck($table->lookup('BA', '101011000')['municipal'] === '4.2500', 'vírgula decimal citada interpretada corretamente');
        layoutCheck($table->lookup('BA', '101011000')['source_hash'] === hash('sha256', $input), 'hash conserva separador e organização originais');
    }
    $aliases = ['Codigo'=>'CÓDIGO', 'Excecao'=>'Exceção', 'Tipo'=>'TIPO', 'UF'=>'uf', 'Tabela'=>'Versão',
        'Descricao'=>'Descrição', 'AliquotaNacionalFederal'=>'Alíquota Nacional Federal',
        'AliquotaImportadosFederal'=>'Alíquota Importados Federal', 'AliquotaEstadual'=>'Alíquota Estadual',
        'AliquotaMunicipal'=>'Alíquota Municipal', 'VigenciaInicio'=>'Vigência Início', 'VigenciaFim'=>'Vigência Fim'];
    $accented = []; foreach (layoutRow(['Tipo'=>'NBS']) as $key=>$value) { $accented[$aliases[$key]] = $value; }
    foreach ([layoutCsv([$accented]), "\xef\xbb\xbf" . layoutCsv([$accented]), mb_convert_encoding(layoutCsv([$accented]), 'Windows-1252', 'UTF-8')] as $encoded) {
        layoutCheck($table->import('BA', $encoded, 'consulta (2).CSV', 'TESTE074')['status'] === 'current', 'case, acentos e codificação suportados');
        layoutCheck($table->lookup('BA', '101011000')['source_hash'] === hash('sha256', $encoded), 'BOM ou codificação original conservada no hash');
    }
    $withKey = layoutCsv([layoutRow(['Chave IBPT'=>'ARQUIVO074', 'Fonte'=>'IBPT fonte informada', 'Extra'=>'ignorado'])]);
    layoutCheck($table->import('BA', $withKey, 'consulta.csv')['key'] === 'ARQUIVO074', 'chave presente no CSV não exige campo complementar');
    layoutCheck($table->lookup('BA', '101011000')['fonte'] === 'IBPT fonte informada', 'fonte expressa no arquivo é conservada');
    layoutCheck($table->import('BA', $withKey, 'consulta.csv', 'ARQUIVO074')['status'] === 'current', 'complemento igual à chave expressa é permitido');
    $optional = layoutRow(); unset($optional['Excecao'], $optional['AliquotaImportadosFederal']);
    layoutCheck($table->import('BA', layoutCsv([$optional]), 'consulta.csv', 'TESTE074')['status'] === 'current', 'campos não usados não exigem preenchimento inventado');
    $noUf = layoutRow(['Chave'=>'TESTE074']); unset($noUf['UF']);
    layoutCheck($table->import('BA', layoutCsv([$noUf]), 'TabelaIBPTaxBA26.2.C.csv')['status'] === 'current', 'layout sem UF utiliza o nome original como antes');
    $unchanged = file_get_contents($file);

    $invalid = [
        [$raw, 'consulta.csv', '', 'chave original'],
        [$raw, 'consulta.csv', str_repeat('A', 33), 'chave original'],
        [$raw, 'consulta.csv', 'A-1', 'chave original'],
        [$withKey, 'consulta.csv', 'DIFERENTE', 'diverge'],
        [layoutCsv([layoutRow(['UF'=>'SP'])]), 'consulta.csv', 'TESTE074', 'não corresponde'],
        [layoutCsv([layoutRow(), layoutRow(['Tipo'=>'ncm','Codigo'=>'01012100','UF'=>'SP'])]), 'consulta.csv', 'TESTE074', 'não corresponde'],
        [layoutCsv([layoutRow(['UF'=>''])]), 'consulta.csv', 'TESTE074', 'não corresponde'],
        [$raw, 'SP.csv', 'TESTE074', 'não corresponde'],
        [layoutCsv([$noUf]), 'consulta.csv', 'TESTE074', 'CSV original'],
        [$raw, '../consulta.csv', 'TESTE074', 'CSV original'],
        [$raw, 'consulta.csv.php', 'TESTE074', 'CSV original'],
        [layoutCsv([layoutRow(['Tipo'=>'servico'])]), 'consulta.csv', 'TESTE074', 'Tipo de registro'],
        [layoutCsv([layoutRow(['Tipo'=>'ncm','Codigo'=>'101011000'])]), 'consulta.csv', 'TESTE074', 'não contém serviços'],
        [layoutCsv([layoutRow(['Codigo'=>'10101100'])]), 'consulta.csv', 'TESTE074', 'NBS de 9'],
        [layoutCsv([layoutRow(),layoutRow()]), 'consulta.csv', 'TESTE074', 'duplicado'],
        [layoutCsv([layoutRow(['Excecao'=>'1'])]), 'consulta.csv', 'TESTE074', 'ambíguo'],
        [layoutCsv([layoutRow(['Tabela'=>'26.2.B'])]), 'consulta.csv', 'TESTE074', 'anterior'],
        [layoutCsv([layoutRow(['VigenciaInicio'=>'01/10/2026','VigenciaFim'=>'31/10/2026'])]), 'consulta.csv', 'TESTE074', 'anterior'],
        [layoutCsv([layoutRow(['VigenciaInicio'=>'01/12/2026','VigenciaFim'=>'31/12/2026'])]), 'consulta.csv', 'TESTE074', 'futura'],
        [layoutCsv([layoutRow(),layoutRow(['Codigo'=>'101012000','Tabela'=>'26.2.D'])]), 'consulta.csv', 'TESTE074', 'divergentes'],
        [layoutCsv([layoutRow(['AliquotaMunicipal'=>'100.0001'])]), 'consulta.csv', 'TESTE074', 'intervalo'],
        [layoutCsv([layoutRow(['AliquotaEstadual'=>'-1'])]), 'consulta.csv', 'TESTE074', 'Alíquota inválida'],
        [str_replace('Codigo;', 'CampoDesconhecido;', $raw), 'consulta.csv', 'TESTE074', 'campos necessários'],
        [layoutCsv([layoutRow(['versao'=>'26.2.C'])]), 'consulta.csv', 'TESTE074', 'duplicadas'],
        [layoutCsv([layoutRow(['nacionalfederal'=>'13.45'])]), 'consulta.csv', 'TESTE074', 'duplicadas'],
        [str_repeat('x',8388609), 'consulta.csv', 'TESTE074', 'limite'],
        ["\xff\xfe".$raw, 'consulta.csv', 'TESTE074', 'Arquivo'],
        ["\0".$raw, 'consulta.csv', 'TESTE074', 'Arquivo'],
    ];
    foreach ($invalid as [$input,$filename,$key,$fragment]) {
        layoutReject(fn() => $table->import('BA', $input, $filename, $key), $fragment);
        layoutCheck(file_get_contents($file) === $unchanged, 'erro preserva integralmente a tabela anterior: ' . $fragment);
    }
    $other = new IbptTable(new IbptStore($directory, hash('sha256','layout-company-b'), $clock), $network, $clock);
    layoutCheck($other->status('BA')['status'] === 'missing', 'outra empresa não vê tabela importada');
    layoutCheck($table->status('BA')['key'] === 'TESTE074', 'falhas não reaproveitam nem substituem chave original');
    layoutCheck($requests === 0, 'nenhum cenário executa requisição externa');

    // Optional local attachment regression; never packages, edits, or installs the supplied data.
    $sample = getenv('IBPT_LAYOUT_SAMPLE');
    if (is_string($sample) && $sample !== '') {
        $sampleRaw = file_get_contents($sample);
        if (!is_string($sampleRaw)) { throw new RuntimeException('Não foi possível ler a amostra solicitada.'); }
        layoutReject(fn() => $other->import('BA', $sampleRaw, basename($sample)), 'chave original');
        $sampleResult = $other->import('BA', $sampleRaw, basename($sample), 'TESTELOCAL074');
        layoutCheck($sampleResult['service_count'] === 860 && $sampleResult['version'] === '26.2.C', 'amostra alternativa possui860NBS; chave exclusivamente sintética neste teste isolado');
        layoutCheck($sampleResult['valid_from'] === '2026-10-20' && $sampleResult['valid_to'] === '2026-11-30', 'vigência da amostra lida sem modificação');
        layoutCheck($other->lookup('BA','101011000')['source_hash'] === hash('sha256',$sampleRaw), 'amostra mantém hash original');
        layoutCheck(file_get_contents($sample) === $sampleRaw, 'arquivo do usuário permanece intacto');
    }
    echo 'OK: ' . $checks . " verificações de layouts alternativos IBPT.\n";
} finally { layoutClean($directory); }
