<?php
declare(strict_types=1);
namespace NfseNacional;

/** Local monetary estimates, kept distinct from the fiscal authority's ISSQN calculation. */
final class TaxEstimate
{
    /** Exact positive percentage, four rate decimals and half-up rounding to currency cents. */
    public static function percentage(mixed $base, mixed $rate): string
    {
        $minor = Decimal::minor($base);
        $normalized = self::rate($rate);
        $units = (int) str_replace('.', '', $normalized);
        if ($minor < 0) { throw new \InvalidArgumentException('A base de cálculo não pode ser negativa.'); }
        // Quotient/remainder splitting avoids multiplying a 14-digit amount by 10^6.
        $quotient = intdiv($minor, 1000000);
        $remainder = $minor % 1000000;
        $value = $quotient * $units + intdiv($remainder * $units + 500000, 1000000);
        return Decimal::fromMinor($value);
    }

    public static function base(array $values): string
    {
        $amount = Decimal::minor($values['service_amount'] ?? '0');
        $discount = Decimal::minor($values['unconditional_discount'] ?? '0');
        if ($amount < 0 || $discount < 0 || $discount > $amount) { throw new \InvalidArgumentException('Confira o valor dos serviços e o desconto incondicional para calcular os tributos.'); }
        return Decimal::fromMinor($amount - $discount);
    }

    /** Null means no reliable local calculation, never a fabricated official zero. */
    public static function iss(array $values): ?array
    {
        if ((string) ($values['iss_taxation'] ?? '') !== '1' || ($values['iss_rate'] ?? '') === '') { return null; }
        $issRate = Decimal::normalize($values['iss_rate'], 2);
        if (Decimal::minor($issRate) < 0 || Decimal::minor($issRate) > 999) {
            throw new \InvalidArgumentException('A alíquota de ISSQN deve ter até duas casas decimais e respeitar o limite do leiaute.');
        }
        $base = self::base($values); $rate = self::rate($issRate);
        return ['base' => $base, 'rate' => $rate, 'amount' => self::percentage($base, $rate),
            'retained' => in_array((string) ($values['retention'] ?? ''), ['2', '3'], true)];
    }

    /** A validated table snapshot supplies rates; no saved manual totals can override this calculation. */
    public static function ibpt(array $values, array $provider, array $snapshot): array
    {
        $uf = IbptTable::uf((string) ($provider['state'] ?? ''));
        $nbs = IbptTable::nbs((string) ($snapshot['nbs'] ?? ''));
        // Structural verification only; the service checks validity against its actual emission date.
        $snapshot = IbptTable::validateSnapshot($snapshot, $uf, $nbs, (string) ($snapshot['valid_from'] ?? ''));
        $base = self::base($values);
        $result = ['base' => $base];
        foreach (['total_federal' => 'nacionalfederal', 'total_state' => 'estadual', 'total_municipal' => 'municipal'] as $key => $source) {
            $result[$key] = self::percentage($base, $snapshot[$source]);
        }
        $result['total'] = Decimal::fromMinor(Decimal::minor($result['total_federal']) + Decimal::minor($result['total_state']) + Decimal::minor($result['total_municipal']));
        $text = (string) ($provider['simple_status'] ?? '') === '3' ? 'Optante do Simples Nacional. ' : '';
        $text .= 'Trib. aprox. R$ ' . self::money($result['total_federal']) . ' Federal, R$ ' . self::money($result['total_state'])
            . ' Estadual e R$ ' . self::money($result['total_municipal']) . ' Municipal. Fonte: IBPT [' . $snapshot['chave'] . '].';
        if ((string) ($provider['simple_status'] ?? '') === '3' && ($values['simple_rate'] ?? '') !== '') {
            $text .= ' Alíquota do Simples Nacional: ' . str_replace('.', ',', self::rate($values['simple_rate'])) . '%.';
        }
        $result['text'] = $text; $result['snapshot'] = $snapshot;
        return $result;
    }

    private static function rate(mixed $value): string
    {
        $value = Decimal::normalize($value, 4);
        $units = (int) str_replace('.', '', $value);
        if ($units < 0 || $units > 1000000) { throw new \InvalidArgumentException('Informe uma alíquota de 0 a 100%, com até quatro casas decimais.'); }
        return $value;
    }

    private static function money(string $value): string
    {
        [$integer, $fraction] = explode('.', Decimal::normalize($value));
        return preg_replace('/\B(?=(?:[0-9]{3})+(?![0-9]))/', '.', $integer) . ',' . $fraction;
    }
}
