<?php
declare(strict_types=1);
namespace NfseNacional;

/** Exact fixed-point arithmetic for the supported monetary range. */
final class Decimal
{
    public static function normalize($value, int $scale = 2): string
    {
        if (!is_scalar($value) || is_bool($value)) {
            throw new \InvalidArgumentException('Informe um valor decimal válido.');
        }
        $text = trim((string) $value);
        if (strpos($text, ',') !== false) {
            if (strpos($text, '.') !== false) {
                if (!preg_match('/^-?\d{1,3}(\.\d{3})*,\d+$/D', $text)) {
                    throw new \InvalidArgumentException('Formato monetário inválido.');
                }
                $text = str_replace('.', '', $text);
            }
            $text = str_replace(',', '.', $text);
        }
        if (!preg_match('/^(-?)(\d{1,12})(?:\.(\d{1,6}))?$/D', $text, $m)) {
            throw new \InvalidArgumentException('Informe até 12 dígitos inteiros, sem notação científica.');
        }
        $fraction = $m[3] ?? '';
        if (strlen($fraction) > $scale && trim(substr($fraction, $scale), '0') !== '') {
            throw new \InvalidArgumentException('Há casas decimais excedentes; revise o valor sem arredondamento automático.');
        }
        $integer = ltrim($m[2], '0') ?: '0';
        $fraction = str_pad(substr($fraction, 0, $scale), $scale, '0');
        $negative = $m[1] === '-' && ($integer !== '0' || trim($fraction, '0') !== '');
        return ($negative ? '-' : '') . $integer . ($scale ? '.' . $fraction : '');
    }

    public static function minor($value): int
    {
        return (int) str_replace('.', '', self::normalize($value));
    }

    public static function subtract($a, $b): string
    {
        return self::fromMinor(self::minor($a) - self::minor($b));
    }

    public static function fromMinor(int $value): string
    {
        return ($value < 0 ? '-' : '') . intdiv(abs($value), 100) . '.' . str_pad((string) (abs($value) % 100), 2, '0', STR_PAD_LEFT);
    }
}
