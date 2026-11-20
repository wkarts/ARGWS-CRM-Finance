<?php
declare(strict_types=1);
namespace NfseNacional;

/** Application-owned diagnostic; no upstream message or document content is exposed. */
final class SyncFailure extends \RuntimeException
{
    public function __construct(public readonly string $stage, \Throwable $previous)
    {
        $messages = [
            'certificate' => 'Não foi possível utilizar o certificado A1 desta empresa na consulta nacional. Confira a disponibilidade e a autorização do certificado.',
            'request' => 'Não foi possível concluir a comunicação com o ADN. Consulte os logs da consulta.',
            'batch_validation' => 'O lote devolvido pelo ADN não passou na conferência de sequência. Consulte os logs antes de repetir.',
            'document_validation' => 'Um documento do lote não pôde ser conferido. Consulte os logs; nenhum documento nem NSU desse lote foi descartado.',
            'persistence' => 'Não foi possível confirmar a gravação do lote. Verifique o acesso ao banco e consulte os logs.',
        ];
        parent::__construct(($messages[$stage] ?? 'Não foi possível concluir a consulta nacional.') . ' O cursor confirmado e o acervo anterior foram preservados.', 0, $previous);
    }
}
