<?php
declare(strict_types=1);
namespace NfseNacional;
final class AutomationPolicy
{
    public static function rule(array $settings, string $event): array
    {
        if (!in_array($event, ['paid', 'created'], true)) { throw new \InvalidArgumentException('Tipo de automação inválido.'); }
        if (isset($settings['automations'][$event])) { return $settings['automations'][$event] + ['trigger' => $event]; }
        $old = $settings['automation'] ?? [];
        return (($old['trigger'] ?? '') === $event ? $old : ['mode' => 'disabled', 'delay_minutes' => '30', 'staff_id' => '0']) + ['trigger' => $event];
    }
    public static function enabled(array $settings): bool
    {
        return self::rule($settings, 'paid')['mode'] !== 'disabled' || self::rule($settings, 'created')['mode'] !== 'disabled';
    }
    public static function sanitize(array $row): array
    {
        if (!in_array($row['mode'] ?? '', ['disabled', 'all', 'selected'], true)
            || !in_array($row['trigger'] ?? '', ['paid', 'created'], true)
            || !is_string($row['delay_minutes'] ?? null) || !preg_match('/^[0-9]{1,5}$/D', $row['delay_minutes'])
            || (int) $row['delay_minutes'] > 43200) { throw new \InvalidArgumentException('Configuração de automação inválida. Use de 0 a 43200 minutos.'); }
        return $row;
    }
    public static function eligible(array $config, object $invoice, bool $selected, string $event): bool
    {
        return ($config['mode'] ?? 'disabled') !== 'disabled'
            && ($config['trigger'] ?? '') === $event
            && (($config['mode'] ?? '') === 'all' || $selected)
            && !in_array((int) $invoice->status, [5, 6], true)
            && ($event !== 'paid' || (int) $invoice->status === 2);
    }
    public static function due(string $created, int $minutes): string
    {
        return (new \DateTimeImmutable($created))->modify('+' . $minutes . ' minutes')->format('Y-m-d H:i:s');
    }
}
