<?php
declare(strict_types=1);
namespace NfseNacional;

/** Capabilities registered in the native staff and roles forms. No parallel ACL database. */
final class Permissions
{
    public static function capabilities(): array
    {
        return [
            'rates_view' => 'Alíquotas por competência — visualizar',
            'rates_create' => 'Alíquotas por competência — incluir',
            'rates_edit' => 'Alíquotas por competência — editar',
            'rates_delete' => 'Alíquotas por competência — excluir',
            'automation_view' => 'Automações — acompanhar filas',
            'automation_manage' => 'Automações — gerenciar seleções e pendências',
            'report_crm' => 'Relatórios — documentos do CRM',
            'report_issued' => 'Relatórios — NFS-e emitidas no ambiente nacional',
            'report_received' => 'Relatórios — NFS-e recebidas',
            'report_package' => 'Relatórios — pacote mensal',
            'report_export' => 'Relatórios nacionais e pacote mensal — baixar arquivos',
        ];
    }
    public static function reportCapability(string $type): string
    {
        $map = ['crm' => 'report_crm', 'issued' => 'report_issued', 'received' => 'report_received', 'accounting' => 'report_package'];
        if (!isset($map[$type])) { throw new \InvalidArgumentException('Relatório inválido.'); }
        return $map[$type];
    }
    public static function anyReport(): bool
    {
        foreach (['crm', 'issued', 'received', 'accounting'] as $type) { if (nfse_nacional_can(self::reportCapability($type))) { return true; } }
        return false;
    }
    public static function assertReport(string $type): void
    {
        if (!nfse_nacional_can(self::reportCapability($type))) { throw new \RuntimeException('Você não tem permissão para acessar este relatório.', 403); }
    }
}
