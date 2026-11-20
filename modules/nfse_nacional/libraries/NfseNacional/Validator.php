<?php
declare(strict_types=1);
namespace NfseNacional;

final class Validator
{
    public static function taxId(string $id): bool
    {
        if (preg_match('/^\d{11}$/D', $id) && !preg_match('/^(\d)\1{10}$/D', $id)) {
            for ($size = 9; $size <= 10; $size++) {
                $sum = 0;
                for ($i = 0; $i < $size; $i++) { $sum += (int) $id[$i] * ($size + 1 - $i); }
                $digit = 11 - $sum % 11;
                if ((int) $id[$size] !== ($digit >= 10 ? 0 : $digit)) { return false; }
            }
            return true;
        }
        if (!preg_match('/^[A-Z0-9]{12}\d{2}$/D', $id) || preg_match('/^(\d)\1{13}$/D', $id)) { return false; }
        for ($size = 12; $size <= 13; $size++) {
            $sum = 0; $weight = 2;
            for ($i = $size - 1; $i >= 0; $i--) {
                $sum += (ord($id[$i]) - 48) * $weight;
                $weight = $weight === 9 ? 2 : $weight + 1;
            }
            $digit = 11 - $sum % 11;
            if ((int) $id[$size] !== ($digit >= 10 ? 0 : $digit)) { return false; }
        }
        return true;
    }

    public static function recipient(array $r): array
    {
        $errors = [];
        if (!self::taxId((string) ($r['tax_id'] ?? ''))) { $errors[] = 'Informe CPF/CNPJ válido do tomador.'; }
        if (mb_strlen((string) ($r['name'] ?? ''), 'UTF-8') < 2) { $errors[] = 'Informe o nome do tomador.'; }
        if (!preg_match('/^[1-9]\d{6}$/D', (string) ($r['municipality_code'] ?? ''))) { $errors[] = 'Informe o código IBGE de 7 dígitos do município do tomador.'; }
        if (!preg_match('/^\d{8}$/D', (string) ($r['zip'] ?? ''))) { $errors[] = 'Informe o CEP de 8 dígitos do tomador.'; }
        foreach (['street' => 'logradouro', 'number' => 'número (ou S/N)', 'district' => 'bairro'] as $key => $name) {
            if (trim((string) ($r[$key] ?? '')) === '') { $errors[] = 'Informe ' . $name . ' do tomador.'; }
        }
        if (!empty($r['email']) && !filter_var($r['email'], FILTER_VALIDATE_EMAIL)) { $errors[] = 'E-mail do tomador inválido.'; }
        return $errors;
    }

    public static function draft(array $d): array
    {
        $e = self::recipient($d['recipient'] ?? []);
        $p = $d['provider'] ?? []; $s = $d['service'] ?? []; $v = $d['values'] ?? []; $rtc = $d['ibscbs'] ?? [];
        if (!self::taxId((string) ($p['tax_id'] ?? ''))) { $e[] = 'Configure CPF/CNPJ válido do emitente.'; }
        foreach (['Emissão' => $p['municipality_code'] ?? '', 'Prestação' => $s['municipality_code'] ?? ''] as $label => $code) {
            if (!preg_match('/^[1-9]\d{6}$/D', (string) $code)) { $e[] = $label . ': informe o código IBGE de 7 dígitos.'; }
        }
        if (!in_array($p['simple_status'] ?? '', ['1', '2', '3'], true)) { $e[] = 'Informe a opção correta pelo Simples Nacional.'; }
        if (($p['simple_status'] ?? '') === '3' && !in_array($p['simple_apportionment'] ?? '', ['1', '2', '3'], true)) { $e[] = 'Informe o regime de apuração do optante pelo Simples Nacional.'; }
        if (!in_array($p['special_regime'] ?? '', ['0', '1', '2', '3', '4', '5', '6', '9'], true)) { $e[] = 'Regime especial de tributação inválido.'; }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($d['competence'] ?? ''));
        if (!$date || $date->format('Y-m-d') !== ($d['competence'] ?? '') || $date->format('Y-m-d') > date('Y-m-d')) { $e[] = 'Informe uma competência válida, não futura.'; }
        if (!preg_match('/^\d{6}$/D', (string) ($s['national_code'] ?? ''))) { $e[] = 'Selecione o serviço no catálogo nacional da NFS-e.'; }
        elseif (!ServiceCatalog::has((string) $s['national_code'])) { $e[] = 'O código do serviço não consta no catálogo nacional da NFS-e. Revise o serviço selecionado.'; }
        elseif (in_array((string) ($d['environment'] ?? ''), ['1', '2'], true)
            && !ServiceCatalog::has((string) $s['national_code'], (string) $d['environment'])) {
            $e[] = 'Este código de serviço está disponível somente em testes no catálogo incluído. Selecione um código liberado para o ambiente da nota.';
        }
        if (trim((string) ($s['description'] ?? '')) === '') { $e[] = 'Descreva o serviço prestado.'; }
        if (!empty($s['municipal_code']) && !preg_match('/^\d{3}$/D', $s['municipal_code'])) { $e[] = 'Código de tributação municipal deve ter 3 dígitos.'; }
        if (!empty($s['nbs']) && !preg_match('/^\d{9}$/D', $s['nbs'])) { $e[] = 'NBS deve ter 9 dígitos.'; }
        try {
            $amount = Decimal::minor($v['service_amount'] ?? '0');
            $unc = Decimal::minor($v['unconditional_discount'] ?? '0');
            $cond = Decimal::minor($v['conditional_discount'] ?? '0');
            if ($amount <= 0 || $unc < 0 || $cond < 0 || ($unc + $cond) >= $amount) { $e[] = 'Revise o valor do serviço e os descontos: o valor líquido deve ser positivo.'; }
            foreach (['iss_rate', 'simple_rate'] as $key) {
                if (($v[$key] ?? '') !== '') {
                    $rate = Decimal::minor($v[$key]);
                    if ($rate < 0 || $rate > 10000) { $e[] = 'Alíquota fora do intervalo 0 a 100%.'; }
                    elseif ($key === 'iss_rate' && $rate > 500) { $e[] = 'A alíquota do ISSQN não pode ser superior a 5%.'; }
                }
            }
        } catch (\InvalidArgumentException $error) { $e[] = $error->getMessage(); }
        if (!in_array($v['iss_taxation'] ?? '', ['1', '4'], true)) { $e[] = 'Esta versão atende operação tributável e não incidência; exportação e imunidade exigem grupos específicos ainda não disponíveis.'; }
        if (!in_array($v['retention'] ?? '', ['1', '2', '3'], true)) { $e[] = 'Tipo de retenção do ISSQN inválido.'; }
        if (!in_array($v['total_tax_mode'] ?? '', ['none', 'amount', 'simple', 'ibpt'], true)) { $e[] = 'Selecione a forma de informar tributos aproximados.'; }
        if (($v['total_tax_mode'] ?? '') === 'ibpt') {
            try {
                $estimate = DraftTax::ibpt($d);
                foreach (['total_federal', 'total_state', 'total_municipal'] as $key) {
                    if (Decimal::normalize($v[$key] ?? '') !== $estimate[$key]) {
                        $e[] = 'Salve o rascunho para atualizar os tributos aproximados pelo IBPT após revisar os valores.';
                        break;
                    }
                }
            } catch (\Throwable $error) { $e[] = $error->getMessage(); }
        }
        if (($v['total_tax_mode'] ?? '') === 'simple' && (($v['simple_rate'] ?? '') === '' || ($p['simple_status'] ?? '') !== '3')) { $e[] = 'Percentual do Simples exige optante ME/EPP e alíquota informada.'; }
        if (($rtc['enabled'] ?? '0') === '1') {
            foreach (['operation_indicator' => 6, 'cst' => 3, 'class_code' => 6] as $key => $length) {
                if (!preg_match('/^\d{' . $length . '}$/D', (string) ($rtc[$key] ?? ''))) { $e[] = 'Complete o enquadramento IBS/CBS: ' . $key . '.'; }
            }
            if (($rtc['destination_indicator'] ?? '') !== '0') { $e[] = 'Nesta versão, o destinatário IBS/CBS deve ser o próprio tomador.'; }
        }
        return array_values(array_unique($e));
    }
}
