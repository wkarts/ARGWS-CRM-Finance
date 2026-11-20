<?php

declare(strict_types=1);

namespace Multiempresa\Infrastructure;

use Multiempresa\Domain\{Failure,Id};

/** SQL lexer: delimiters and identifiers are never rewritten inside string literals. */
final class SqlScript
{
    public static function statements(string $sql): array
    {
        $out = [];
        $buffer = '';
        $quote = null;
        $n = strlen($sql);
        for ($i = 0;
            $i < $n;
            $i++) {
            $c = $sql[$i];
            $next = $sql[$i + 1] ?? '';
            if ($quote !== null) {
                $buffer .= $c;
                if ($c === '\\' && $quote !== '`' && $i + 1 < $n) {
                    $buffer .= $sql[++$i];
                } elseif ($c === $quote) {
                    if ($next === $quote) {
                        $buffer .= $sql[++$i];
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                $buffer .= $c;
                continue;
            }
            if (($c === '-' && $next === '-' && preg_match('/\s/', $sql[$i + 2] ?? ' ')) || $c === '#') {
                while ($i < $n && $sql[$i] !== "\n") {
                    $i++;
                }
                $buffer .= "\n";
                continue;
            }
            if ($c === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) {
                    throw new Failure('sql_comment', 'Comentário SQL incompleto.');
                }
                $i = $end + 1;
                $buffer .= ' ';
                continue;
            }
            if ($c === ';') {
                if (trim($buffer) !== '') {
                    $out[] = trim($buffer);
                }
                $buffer = '';
            } else {
                $buffer .= $c;
            }
        }
        if ($quote !== null) {
            throw new Failure('sql_string', 'String SQL incompleta.');
        }
        if (trim($buffer) !== '') {
            $out[] = trim($buffer);
        }
        return $out;
    }
    public static function rewrite(string $sql, string $from, string $to, array $tables = []): string
    {
        Id::prefix($from);
        Id::prefix($to);
        if ($from === $to) { return $sql; }
        if ($from === '') {
            if (!$tables) { throw new Failure('sql_table_map', 'Informe as tabelas do snapshot sem prefixo.'); }
            $map=[];
            foreach ($tables as $table) { $map[Id::identifier($table)] = Id::identifier($to.$table); }
            return \Multiempresa\Perfex\ModuleRuntime::rewrite($sql, $map);
        }
        return preg_replace_callback('/\x27(?:\\\\.|\x27\x27|[^\x27\\\\])*\x27|"(?:\\\\.|""|[^"\\\\])*"|`([^`]+)`/s', static function ($m) use ($from, $to) {
            if (isset($m[1]) && str_starts_with($m[1], $from)) {
                return '`'.$to.substr($m[1], strlen($from)).'`';
            }
            return $m[0];
        }, $sql) ?? throw new Failure('sql_rewrite', 'Falha ao preparar SQL.');
    }
    public static function import(\PDO $pdo, string $script, string $from, string $to, array $tables = []): void
    {
        foreach (self::statements($script) as $s) {
            if (preg_match('/\A(?:SET\s|START TRANSACTION|COMMIT)/i', $s)) {
                continue;
            }
            if (!preg_match('/\A(?:CREATE TABLE|ALTER TABLE|INSERT INTO)\s+`'.preg_quote($from, '/').'[a-zA-Z0-9_]+`/i', $s)) {
                throw new Failure('sql_unsupported', 'O esquema contém instrução não suportada.');
            }
            $pdo->exec(self::rewrite($s, $from, $to, $tables));
        }
    }
    /** Read generated snapshots with memory bounded by the largest SQL statement. */
    public static function importStream(\PDO $pdo, $stream, string $from, string $to, array $tables = []): void
    {
        foreach (self::streamStatements($stream) as $statement) {
            self::import($pdo, $statement.';', $from, $to, $tables);
        }
    }
    public static function streamStatements($stream): \Generator
    {
        $buffer = '';
        $quote = null;
        $comment = false;
        while (($line = fgets($stream)) !== false) {
            $n = strlen($line);
            for ($i = 0;
                $i < $n;
                $i++) {
                $c = $line[$i];
                $next = $line[$i + 1] ?? '';
                if ($comment) {
                    if ($c === '*' && $next === '/') {
                        $comment = false;
                        $i++;
                        $buffer .= ' ';
                    }
                    continue;
                }
                if ($quote !== null) {
                    $buffer .= $c;
                    if ($c === '\\' && $quote !== '`' && $i + 1 < $n) {
                        $buffer .= $line[++$i];
                    } elseif ($c === $quote) {
                        if ($next === $quote) {
                            $buffer .= $line[++$i];
                        } else {
                            $quote = null;
                        }
                    }
                    continue;
                }
                if ($c === "'" || $c === '"' || $c === '`') {
                    $quote = $c;
                    $buffer .= $c;
                } elseif ($c === '#' || ($c === '-' && $next === '-' && preg_match('/\s/', $line[$i + 2] ?? ' '))) {
                    $buffer .= "\n";
                    break;
                } elseif ($c === '/' && $next === '*') {
                    $comment = true;
                    $i++;
                } elseif ($c === ';') {
                    if (trim($buffer) !== '') {
                        yield trim($buffer);
                    }
                    $buffer = '';
                } else {
                    $buffer .= $c;
                }
            }
        }
        if ($quote !== null || $comment || !feof($stream)) {
            throw new Failure('sql_stream', 'Snapshot SQL incompleto.');
        }
        if (trim($buffer) !== '') {
            yield trim($buffer);
        }
    }
}
