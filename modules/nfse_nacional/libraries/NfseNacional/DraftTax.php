<?php
declare(strict_types=1);
namespace NfseNacional;

/** Server-owned IBPT snapshot. A failed lookup never destroys a saved draft or creates zero tax. */
final class DraftTax
{
    private const STATES = ['11' => 'RO', '12' => 'AC', '13' => 'AM', '14' => 'RR', '15' => 'PA', '16' => 'AP', '17' => 'TO',
        '21' => 'MA', '22' => 'PI', '23' => 'CE', '24' => 'RN', '25' => 'PB', '26' => 'PE', '27' => 'AL', '28' => 'SE', '29' => 'BA',
        '31' => 'MG', '32' => 'ES', '33' => 'RJ', '35' => 'SP', '41' => 'PR', '42' => 'SC', '43' => 'RS',
        '50' => 'MS', '51' => 'MT', '52' => 'GO', '53' => 'DF'];

    /** Never derive the table UF from the customer or from the service location. */
    public static function state(array $provider): string
    {
        $uf = strtoupper(Config::text($provider['state'] ?? '', 2));
        $municipality = Config::text($provider['municipality_code'] ?? '', 7);
        $derived = preg_match('/^[0-9]{7}$/D', $municipality) ? (self::STATES[substr($municipality, 0, 2)] ?? '') : '';
        if ($uf !== '' && !in_array($uf, self::STATES, true)) { throw new \InvalidArgumentException('Configure uma UF válida para o estabelecimento emitente antes de consultar o IBPT.'); }
        if ($uf !== '' && $derived !== '' && $uf !== $derived) { throw new \InvalidArgumentException('A UF do emitente não corresponde ao seu código IBGE. Revise os parâmetros da empresa.'); }
        if ($uf === '') { $uf = $derived; }
        if ($uf === '') { throw new \InvalidArgumentException('Informe a UF ou o código IBGE do emitente para calcular os tributos aproximados pelo IBPT.'); }
        return $uf;
    }

    /** The server emission date determines whether renewal is needed; competence never hides expiration. */
    public static function date(array $draft, ?string $date = null): string
    {
        if ($date === null) {
            $issued = $draft['issued_at'] ?? '';
            if (!is_string($issued)) { throw new \InvalidArgumentException('Data da emissão inválida para conferir a vigência do IBPT.'); }
            if ($issued !== '') {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:[+-]\d{2}:\d{2}|Z)$/D', $issued)) {
                    throw new \InvalidArgumentException('Data da emissão inválida para conferir a vigência do IBPT.');
                }
                $date = substr($issued, 0, 10);
            } else { $date = (new \DateTimeImmutable('now', new \DateTimeZone('America/Bahia')))->format('Y-m-d'); }
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) { throw new \InvalidArgumentException('Data de vigência inválida.'); }
        return $date;
    }

    /** Called only while preparing/reviewing an editable draft, never while retrying signed XML. */
    public static function refresh(array $draft, ?callable $lookup = null, ?string $date = null): array
    {
        if (($draft['values']['total_tax_mode'] ?? '') !== 'ibpt') {
            unset($draft['ibpt'], $draft['ibpt_error']);
            return $draft;
        }
        try {
            $date = self::date($draft, $date);
            $uf = self::state($draft['provider'] ?? []);
            $nbs = Config::text($draft['service']['nbs'] ?? '', 9);
            if (!preg_match('/^[0-9]{9}$/D', $nbs)) { throw new \InvalidArgumentException('Informe o NBS de 9 dígitos do serviço para calcular os tributos aproximados pelo IBPT.'); }
            $snapshot = null;
            if (isset($draft['ibpt']) && is_array($draft['ibpt'])) {
                try { $snapshot = IbptTable::validateSnapshot($draft['ibpt'], $uf, $nbs, $date); }
                catch (\Throwable $ignored) { /* A changed issuer, NBS or future validity requires a fresh lookup. */ }
            }
            if ($snapshot === null || $date > $snapshot['valid_to']) {
                $lookup = $lookup ?? static fn(string $state, string $code, string $day): array => IbptTable::current()->lookup($state, $code, $day);
                try {
                    $snapshot = IbptTable::validateSnapshot($lookup($uf, $nbs, $date), $uf, $nbs, $date);
                } catch (\Throwable $error) {
                    if ($snapshot === null) { throw $error; }
                    // A saved, verified, expired quote remains usable if the source is unreachable.
                }
            }
            $estimate = TaxEstimate::ibpt($draft['values'], array_replace($draft['provider'], ['state' => $uf]), $snapshot);
            $draft['ibpt'] = $snapshot;
            foreach (['total_federal', 'total_state', 'total_municipal'] as $key) { $draft['values'][$key] = $estimate[$key]; }
            unset($draft['ibpt_error']);
        } catch (\Throwable $error) {
            unset($draft['ibpt']);
            $draft['ibpt_error'] = mb_substr($error->getMessage(), 0, 600);
        }
        return $draft;
    }

    /** Read-only verification used by validation, the DPS builder and the PDF. */
    public static function ibpt(array $draft, ?string $date = null): array
    {
        if (($draft['values']['total_tax_mode'] ?? '') !== 'ibpt') { throw new \InvalidArgumentException('O documento não está configurado para cálculo pelo IBPT.'); }
        if (!empty($draft['ibpt_error']) || !isset($draft['ibpt']) || !is_array($draft['ibpt'])) {
            $detail = is_string($draft['ibpt_error'] ?? null) ? trim($draft['ibpt_error']) : '';
            throw new \RuntimeException($detail !== '' ? $detail : 'Salve o rascunho para consultar a tabela IBPT e conferir os tributos aproximados.');
        }
        $uf = self::state($draft['provider'] ?? []);
        $snapshot = IbptTable::validateSnapshot($draft['ibpt'], $uf,
            Config::text($draft['service']['nbs'] ?? '', 9), self::date($draft, $date));
        return TaxEstimate::ibpt($draft['values'] ?? [], array_replace($draft['provider'] ?? [], ['state' => $uf]), $snapshot);
    }

    public static function information(array $draft): string
    {
        return ($draft['values']['total_tax_mode'] ?? '') === 'ibpt' ? self::ibpt($draft)['text'] : '';
    }

    /** Public UI data contains only fiscal display values, never the certificate or table cache path. */
    public static function summary(array $draft): array
    {
        $values = $draft['values'] ?? [];
        $summary = ['ibpt' => null, 'ibpt_error' => '', 'iss' => null, 'inputs' => [
            'nbs' => (string) ($draft['service']['nbs'] ?? ''),
            'service_amount' => (string) ($values['service_amount'] ?? ''),
            'unconditional_discount' => (string) ($values['unconditional_discount'] ?? ''),
            'simple_rate' => (string) ($values['simple_rate'] ?? ''),
            'total_tax_mode' => (string) ($values['total_tax_mode'] ?? 'none'),
        ]];
        try { $summary['iss'] = TaxEstimate::iss($values); } catch (\Throwable $ignored) { }
        if ($summary['inputs']['total_tax_mode'] === 'ibpt') {
            try { $summary['ibpt'] = self::ibpt($draft); }
            catch (\Throwable $error) { $summary['ibpt_error'] = mb_substr($error->getMessage(), 0, 600); }
        }
        return $summary;
    }
}
