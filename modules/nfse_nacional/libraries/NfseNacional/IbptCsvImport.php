<?php
declare(strict_types=1);
namespace NfseNacional;

/** Explicit upload layouts only; no guessed fiscal classifications or inferred source keys. */
final class IbptCsvImport
{
    private const MAX_BYTES = 8388608;
    private const MAX_ROWS = 60000;
    private const REQUIRED = ['codigo', 'tipo', 'descricao', 'nacionalfederal', 'estadual', 'municipal',
        'vigenciainicio', 'vigenciafim', 'versao'];
    private const COLUMNS = ['codigo', 'ex', 'tipo', 'descricao', 'nacionalfederal', 'importadosfederal',
        'estadual', 'municipal', 'vigenciainicio', 'vigenciafim', 'chave', 'versao', 'fonte'];
    private const ALIASES = [
        'codigo' => 'codigo', 'ex' => 'ex', 'excecao' => 'ex', 'tipo' => 'tipo', 'uf' => 'uf',
        'descricao' => 'descricao', 'nacionalfederal' => 'nacionalfederal',
        'aliquotanacionalfederal' => 'nacionalfederal', 'importadosfederal' => 'importadosfederal',
        'aliquotaimportadosfederal' => 'importadosfederal', 'estadual' => 'estadual', 'aliquotaestadual' => 'estadual',
        'municipal' => 'municipal', 'aliquotamunicipal' => 'municipal',
        'vigenciainicio' => 'vigenciainicio', 'iniciovigencia' => 'vigenciainicio',
        'vigenciafim' => 'vigenciafim', 'fimvigencia' => 'vigenciafim',
        'chave' => 'chave', 'chaveibpt' => 'chave', 'fonte' => 'fonte', 'tabela' => 'versao', 'versao' => 'versao',
    ];

    /** Return canonical service CSV, keeping UF validation separate from the legacy filename fallback. */
    public static function normalize(string $raw, string $uf, string $key = ''): array
    {
        $uf = IbptTable::uf($uf);
        if (strlen($raw) < 40 || strlen($raw) > self::MAX_BYTES || str_contains($raw, "\0")
            || str_starts_with($raw, "\xff\xfe") || str_starts_with($raw, "\xfe\xff")) {
            throw new \InvalidArgumentException('Arquivo da tabela IBPT inválido ou acima do limite permitido.');
        }
        $key = trim($key);
        if ($key !== '' && !preg_match('/\A[A-Za-z0-9]{1,32}\z/D', $key)) {
            throw new \InvalidArgumentException('Informe uma chave original IBPT válida, com até 32 letras e números.');
        }
        if (str_starts_with($raw, "\xef\xbb\xbf")) { $raw = substr($raw, 3); }
        if (!preg_match('//u', $raw)) { $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252'); }
        if (!preg_match('//u', $raw) || strlen($raw) > self::MAX_BYTES * 3) {
            throw new \InvalidArgumentException('Codificação inválida na tabela IBPT.');
        }
        $input = fopen('php://temp/maxmemory:8388608', 'w+b');
        $output = fopen('php://temp/maxmemory:8388608', 'w+b');
        if (!is_resource($input) || !is_resource($output)) {
            if (is_resource($input)) { fclose($input); }
            if (is_resource($output)) { fclose($output); }
            throw new \RuntimeException('Não foi possível ler a tabela IBPT.');
        }
        try {
            if (fwrite($input, $raw) !== strlen($raw)) { throw new \RuntimeException('Não foi possível ler a tabela IBPT.'); }
            [$delimiter, $header, $map] = self::header($input);
            rewind($input); fgetcsv($input, 16384, $delimiter, '"', '');
            $hasUf = in_array('uf', $map, true);
            if (fputcsv($output, self::COLUMNS, ';', '"', '') === false) { throw new \RuntimeException('Não foi possível ler a tabela IBPT.'); }
            $count = 0; $manualKeyUsed = false;
            while (($line = fgetcsv($input, 16384, $delimiter, '"', '')) !== false) {
                if ($line === [null] || $line === ['']) { continue; }
                if (++$count > self::MAX_ROWS || count($line) !== count($header)) {
                    throw new \InvalidArgumentException('Quantidade de registros ou colunas inválida na tabela IBPT.');
                }
                $row = [];
                foreach ($map as $index => $column) { if ($column !== null) { $row[$column] = trim((string) $line[$index]); } }
                if ($hasUf && strtoupper($row['uf'] ?? '') !== $uf) {
                    throw new \InvalidArgumentException('A UF informada no conteúdo da tabela não corresponde à UF ' . $uf . ' do emitente.');
                }
                $type = strtolower($row['tipo']);
                $types = ['ncm' => '0', 'nbs' => '1', 'lc116' => '2', '0' => '0', '1' => '1', '2' => '2'];
                if (!isset($types[$type])) { throw new \InvalidArgumentException('Tipo de registro inválido na tabela IBPT.'); }
                // NCM and LC116 rows never become services merely because their code resembles an NBS.
                if ($types[$type] !== '1') { continue; }
                $row['tipo'] = '1';
                $fileKey = $row['chave'] ?? '';
                if ($fileKey !== '' && $key !== '' && !hash_equals($fileKey, $key)) {
                    throw new \InvalidArgumentException('A chave informada diverge da chave presente no arquivo IBPT.');
                }
                if ($fileKey === '' && $key !== '') { $manualKeyUsed = true; }
                $row['chave'] = $fileKey !== '' ? $fileKey : $key;
                if ($row['chave'] === '') {
                    throw new \InvalidArgumentException('Este arquivo não informa a chave IBPT. Informe a chave original desta versão no campo Chave IBPT antes de importar.');
                }
                // The file is explicitly imported as IBPT; never invent a distributor/domain when source is omitted.
                if (($row['fonte'] ?? '') === '') { $row['fonte'] = 'IBPT'; }
                $values = [];
                foreach (self::COLUMNS as $column) { $values[] = $row[$column] ?? ''; }
                if (fputcsv($output, $values, ';', '"', '') === false || ftell($output) > self::MAX_BYTES) {
                    throw new \InvalidArgumentException('Arquivo da tabela IBPT acima do limite permitido.');
                }
            }
            rewind($output); $csv = stream_get_contents($output, self::MAX_BYTES + 1);
            if (!is_string($csv)) { throw new \RuntimeException('Não foi possível ler a tabela IBPT.'); }
            return ['csv' => $csv, 'has_uf' => $hasUf, 'used_manual_key' => $manualKeyUsed];
        } finally { fclose($input); fclose($output); }
    }

    /** Recognize a delimiter only when its parsed header has the required explicit field names. */
    private static function header($stream): array
    {
        $candidates = [];
        foreach ([';', ',', "\t", '|'] as $delimiter) {
            rewind($stream); $header = fgetcsv($stream, 16384, $delimiter, '"', '');
            if (!is_array($header) || count($header) < count(self::REQUIRED) || count($header) > 64) { continue; }
            $names = array_map(static fn($value): string => self::name((string) $value), $header);
            $map = array_map(static fn(string $name): ?string => self::ALIASES[$name] ?? null, $names);
            if (array_diff(self::REQUIRED, array_filter($map, static fn($value): bool => $value !== null))) { continue; }
            $known = array_filter($map, static fn($value): bool => $value !== null);
            if (in_array('', $names, true) || count(array_unique($names)) !== count($names)
                || count(array_unique($known)) !== count($known)) {
                throw new \InvalidArgumentException('O cabeçalho da tabela IBPT contém colunas duplicadas ou ambíguas.');
            }
            $candidates[] = [$delimiter, $header, $map];
        }
        if (count($candidates) !== 1) {
            throw new \InvalidArgumentException('O formato da tabela IBPT não contém os campos necessários ou possui separador ambíguo.');
        }
        return $candidates[0];
    }

    private static function name(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, ['á'=>'a', 'à'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a', 'é'=>'e', 'è'=>'e', 'ê'=>'e', 'ë'=>'e',
            'í'=>'i', 'ì'=>'i', 'î'=>'i', 'ï'=>'i', 'ó'=>'o', 'ò'=>'o', 'ô'=>'o', 'õ'=>'o', 'ö'=>'o',
            'ú'=>'u', 'ù'=>'u', 'û'=>'u', 'ü'=>'u', 'ç'=>'c']);
        return preg_replace('/[\s_.-]+/u', '', $value) ?? '';
    }
}
