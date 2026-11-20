<?php
declare(strict_types=1);

namespace NfseNacional;

/** Read-only presentation of local workflow and evidence returned by the national system. */
final class FiscalStatus
{
    public static function metadata(array $document): array
    {
        $value = $document['fiscal_meta_json'] ?? '';
        if (!is_string($value) || $value === '') { return []; }
        $data = json_decode($value, true);
        return is_array($data) ? $data : [];
    }

    public static function describe(array $document, ?NfseDocument $note = null): array
    {
        $status = (string) ($document['status'] ?? 'draft');
        $attempts = max(0, (int) ($document['attempts'] ?? 0));
        $test = ($document['environment'] ?? '2') !== '1';
        $meta = self::metadata($document);
        $labels = ['draft' => 'Rascunho', 'ready' => 'Validada', 'processing' => 'Em transmissão',
            'uncertain' => 'Aguardando consulta', 'rejected' => 'Rejeitada',
            'authorized' => 'Autorizada', 'cancelled' => 'Cancelada'];
        $official = in_array($status, ['authorized', 'cancelled'], true) && !empty($document['response_enc']);
        // Cancellation can only be recorded by a correlated registered event, never by invoice status.
        $cancelled = $status === 'cancelled' && $official && ($meta['cancellation_confirmed'] ?? false) === true
            && !empty($document['cancellation_event_enc']);
        $authorized = $status === 'authorized' && $official;
        $transmission = 'Não transmitida';
        $explanation = 'Prévia local dos dados salvos. Ainda não há autorização fiscal.';
        if (in_array($status, ['draft', 'ready'], true)) {
            if ($attempts > 0) {
                $transmission = 'Versão atual não transmitida';
                $explanation = 'Houve uma tentativa anterior. Os dados corrigidos e salvos ainda não foram autorizados. A prévia não tem validade fiscal.';
            } elseif ($status === 'ready') {
                $explanation = 'Dados validados localmente. A DPS ainda não foi enviada ao Sistema Nacional NFS-e.';
            }
        } elseif (in_array($status, ['processing', 'uncertain'], true)) {
            $transmission = 'Envio iniciado; confirmação pendente';
            $explanation = 'Não foi possível confirmar a autorização. Consulte o resultado antes de reenviar. Esta prévia não comprova emissão fiscal.';
        } elseif ($status === 'rejected') {
            $transmission = 'Envio rejeitado; sem autorização';
            $explanation = 'A solicitação foi rejeitada pelo serviço de emissão. Revise os motivos antes de transmitir novamente.';
        } elseif ($authorized) {
            $transmission = $test ? 'Transmitida e autorizada em testes' : 'Transmitida e autorizada';
            $explanation = $test
                ? 'Autorização recebida no ambiente de produção restrita. Documento sem validade jurídica.'
                : 'Autorização confirmada pelo XML recebido do Sistema Nacional NFS-e.';
        } elseif ($cancelled) {
            $transmission = $test ? 'Transmitida e cancelada em testes' : 'Transmitida e cancelada';
            $explanation = 'O cancelamento foi confirmado por evento fiscal vinculado à chave de acesso. O XML original é mantido para consulta histórica.';
            if ($test) { $explanation .= ' Ambiente de testes, sem validade jurídica.'; }
        } else {
            $transmission = 'Situação sem confirmação';
            $explanation = 'Não há evidência fiscal suficiente para confirmar esta situação. Consulte o resultado no ambiente nacional.';
        }
        $checkError = self::text($meta, 'status_check_error', 1000);
        if ($authorized && !empty($meta['replacement_authorized_id'])) {
            $explanation .= ' Há uma NFS-e substituta autorizada. Consulte os eventos da nota original para confirmar e arquivar seu cancelamento.';
        }
        if ($checkError !== '') { $explanation .= ' A última consulta de situação não foi concluída; a situação anterior foi preservada.'; }
        return [
            'status' => $status, 'label' => $labels[$status] ?? 'Situação desconhecida',
            'transmission_label' => $transmission, 'explanation' => $explanation,
            'is_authorized' => $authorized, 'is_cancelled' => $cancelled, 'has_official_xml' => $official,
            'environment' => $test ? '2' : '1', 'attempts' => $attempts,
            'last_submitted_at' => self::text($meta, 'last_submitted_at', 40),
            'processed_at' => $note ? $note->text('dhProc') : self::text($meta, 'processed_at', 40),
            // The national layout identifies authorization by key and nNFSe; do not invent an NF-e nProt.
            'protocol' => self::text($meta, 'protocol', 100),
            'record_number' => $note ? $note->text('nDFSe') : self::text($meta, 'record_number', 30),
            'dps_id' => (string) ($document['dps_id'] ?? ''),
            'access_key' => $official ? (string) ($document['access_key'] ?? '') : '',
            'checked_at' => self::text($meta, 'status_checked_at', 40),
            'last_check_attempt_at' => self::text($meta, 'status_check_attempted_at', 40),
            'check_error' => $checkError,
            'cancelled_at' => $cancelled ? self::text($meta, 'cancelled_at', 40) : '',
            'cancellation_event_id' => $cancelled ? self::text($meta, 'cancellation_event_id', 80) : '',
            'cancellation_record_number' => $cancelled ? self::text($meta, 'cancellation_record_number', 30) : '',
            'cancellation_protocol' => $cancelled ? self::text($meta, 'cancellation_protocol', 100) : '',
        ];
    }

    private static function text(array $data, string $key, int $limit): string
    {
        return isset($data[$key]) && is_scalar($data[$key]) ? mb_substr(trim((string) $data[$key]), 0, $limit) : '';
    }
}
