<?php

declare(strict_types=1);

namespace Multiempresa\Infrastructure;

use Multiempresa\Domain\Failure;

final class Process
{
    public static function run(array $argv, array $input, int $timeout = 300): array
    {
        if(!function_exists('proc_open')||empty($argv[0])||!is_executable($argv[0])){throw new Failure('php_cli','Execução isolada indisponível. Confira o diagnóstico e o PHP CLI nas configurações globais do módulo.',503);}
        $p = proc_open($argv, [0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($p)) {
            throw new Failure('process', 'Não foi possível iniciar o processo isolado.', 503);
        }
        fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $err = '';
        $start = microtime(true);
        $exit = -1;
        try {
            while (true) {
                $out .= stream_get_contents($pipes[1]);
                $err .= stream_get_contents($pipes[2]);
                if (strlen($out) + strlen($err) > 1048576) {
                    throw new Failure('process_output', 'Saída excessiva do processo.', 503);
                }
                $status = proc_get_status($p);
                if (!$status['running']) {
                    $exit = $status['exitcode'];
                    break;
                }
                if (microtime(true) - $start > $timeout) {
                    throw new Failure('process_timeout', 'Tempo limite da rotina excedido.', 503);
                }
                usleep(50000);
            }
            $out .= stream_get_contents($pipes[1]);
            $err .= stream_get_contents($pipes[2]);
        } finally {
            proc_terminate($p);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($p);
        }
        $result = json_decode(trim($out), true);
        if (!is_array($result)) {
            $result = json_decode(trim($err), true);
        }
        if ($exit !== 0 || !is_array($result) || !($result['ok'] ?? false)) {
            $reason = preg_match('/\A[a-z0-9_]+\z/', (string)($result['reason'] ?? '')) ? $result['reason'] : 'process_failed';
            $diagnostic=is_array($result['diagnostic']??null)?$result['diagnostic']:[];
            $table=$diagnostic['missing_table']??'';
            $message=is_string($table)&&preg_match('/\A[a-zA-Z0-9_]+\z/',$table)?'O instalador do módulo tentou acessar a tabela ausente '.$table.'. Revise a seleção de módulos e retome as etapas pendentes.':'Rotina isolada não foi concluída: '.$reason.'.';
            if($reason==='module_changed'&&isset($diagnostic['diagnosis'])&&preg_match('/Módulo alterado sem homologação: ([A-Za-z0-9_]+)/',$diagnostic['diagnosis'],$match)){$message='O pacote do módulo '.$match[1].' mudou desde a revisão. Revise as etapas e confirme a retomada para atualizar sua cópia privada.';}
            if(($diagnostic['reason']??null)==='module_prefix'){$reason='module_prefix';$message='O módulo NFS-e Nacional precisa ser atualizado para funcionar no banco exclusivo sem prefixo. Atualize o módulo e retome as etapas pendentes.';}
            throw new Failure($reason,$message,503,$diagnostic);
        }
        return $result;
    }
}
