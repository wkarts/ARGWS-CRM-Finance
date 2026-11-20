<?php
declare(strict_types=1);
namespace NfseNacional;

/** Monthly Simples and ISS percentages; sharing is explicit and never converts a rate. */
final class CompetenceRate
{
    public static function month(string $value): string
    {
        if (preg_match('~^(0[1-9]|1[0-2])/([0-9]{4})$~D', $value, $m)) { $value = $m[2] . '-' . $m[1]; }
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value)) {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if (!$d || $d->format('Y-m-d') !== $value) { throw new \InvalidArgumentException('Competência inválida.'); }
            $value = substr($value, 0, 7);
        }
        if (!preg_match('/^[1-9][0-9]{3}-(0[1-9]|1[0-2])$/D', $value)) { throw new \InvalidArgumentException('Informe a competência no formato MM/AAAA.'); }
        return $value;
    }

    public static function percentage($value): string
    {
        if (!is_string($value) || !preg_match('/^[0-9]{1,2}(?:[.,][0-9]{1,2})?$/D', trim($value))) {
            throw new \InvalidArgumentException('Informe a alíquota cheia do Simples de 0 a 99,99%, com até duas casas decimais.');
        }
        return Decimal::normalize(trim($value));
    }

    public static function enabled(array $settings): bool
    {
        return ($settings['variable_simple_rate'] ?? '0') === '1' || ($settings['variable_iss_rate'] ?? '0') === '1';
    }

    public static function type(string $type): string
    {
        if (!in_array($type, ['simple', 'iss'], true)) { throw new \InvalidArgumentException('Tipo de alíquota inválido.'); }
        return $type;
    }

    public static function tableSuffix(string $type): string
    {
        return self::type($type) === 'simple' ? 'competence_rates' : 'competence_iss_rates';
    }

    public static function issPercentage($value): string
    {
        try { $rate = self::percentage($value); }
        catch (\InvalidArgumentException $error) { throw new \InvalidArgumentException('Informe a alíquota do ISSQN de 0 a 5%, com até duas casas decimais.'); }
        if (Decimal::minor($rate) > 500) { throw new \InvalidArgumentException('A alíquota do ISSQN não pode ser superior a 5%. Cadastre o percentual próprio do ISSQN.'); }
        return $rate;
    }

    public static function assertSnapshots(array $draft, array $settings): void
    {
        foreach (['simple' => 'variable_simple_rate', 'iss' => 'variable_iss_rate'] as $kind => $flag) {
            if (($settings[$flag] ?? '0') !== '1') { continue; }
            if (($draft[$kind . '_rate_snapshot']['competence'] ?? '') !== self::month($draft['competence'])) {
                throw new ValidationException(['Esta DPS foi reservada sem a alíquota de ' . ($kind === 'simple' ? 'Simples Nacional' : 'ISSQN') . ' por competência. Consulte sua situação antes de tentar uma nova emissão.']);
            }
        }
    }

    public static function apply(array $draft, array $settings, callable $lookup, bool $required = false): array
    {
        if (!self::enabled($settings)) { return $draft; }
        $month = self::month((string) ($draft['competence'] ?? ''));
        $label = substr($month, 5, 2) . '/' . substr($month, 0, 4);
        $errors = []; $rows = [];
        foreach (['simple' => 'variable_simple_rate', 'iss' => 'variable_iss_rate'] as $kind => $flag) {
            if (($settings[$flag] ?? '0') !== '1') { continue; }
            $source = $kind === 'iss' ? ($settings['variable_iss_rate_source'] ?? 'iss') : 'simple';
            self::type($source);
            if (!array_key_exists($source, $rows)) { $rows[$source] = $lookup($month, $source); }
            $row = $rows[$source]; $key = $kind . '_rate';
            unset($draft[$key . '_snapshot'], $draft[$key . '_error']);
            if ($row === null) {
                $draft['values'][$key] = '';
                $error = 'Cadastre a alíquota ' . ($source === 'simple' ? 'cheia do Simples Nacional' : 'do ISSQN') . ' da competência ' . $label . ' antes de emitir.';
            } else {
                $value = self::percentage((string) $row['rate']);
                $draft['values'][$key] = $value;
                $error = $kind === 'iss' && Decimal::minor($value) > 500
                    ? 'A alíquota compartilhada do Simples na competência ' . $label . ' é superior a 5% e não pode ser usada no ISSQN. Selecione alíquota separada e cadastre o percentual próprio do ISSQN.' : '';
                if ($error === '') { $draft[$key . '_snapshot'] = ['competence' => $month, 'rate' => $value, 'source' => $source, 'updated_at' => $row['updated_at'] ?? '']; }
            }
            if ($error !== '') { $draft[$key . '_error'] = $error; $errors[] = $error; }
        }
        if ($required && $errors) { throw new ValidationException(array_values(array_unique($errors))); }
        return $draft;
    }
}
