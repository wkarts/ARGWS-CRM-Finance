<?php
declare(strict_types=1);

namespace NfseNacional;

/**
 * Read-only projection of locally saved draft fields onto the DANFSe layout.
 * This is deliberately not an NFSe XML, a DPS builder or an authorization result.
 * Missing and invalid amounts remain empty; the national authority's calculations
 * and identifiers cannot be manufactured by a preview.
 */
final class DraftPreviewDocument
{
    private array $fields = [];
    private string $environment;

    public function __construct(array $draft, array $document)
    {
        $this->environment = self::scalar($document['environment'] ?? ($draft['environment'] ?? ''));
        if (!in_array($this->environment, ['1', '2'], true)) { $this->environment = ''; }
        $this->putDps('tpAmb', $this->environment);
        $this->putDps('tpEmit', '1');
        $this->putDps('dCompet', $draft['competence'] ?? '');
        // issued_at is populated when the real DPS is reserved, never by this renderer.
        $this->putDps('dhEmi', $draft['issued_at'] ?? '');
        $number = self::scalar($document['dps_number'] ?? '');
        $series = self::scalar($document['dps_series'] ?? '');
        if (preg_match('/^[1-9][0-9]{0,14}$/D', $number) && preg_match('/^[0-9]{1,5}$/D', $series)) {
            $this->putDps('nDPS', $number);
            $this->putDps('serie', $series);
        }
        $provider = self::group($draft, 'provider');
        $recipient = self::group($draft, 'recipient');
        $service = self::group($draft, 'service');
        $values = self::group($draft, 'values');
        $ibs = self::group($draft, 'ibscbs');
        $this->person('prest', $provider);
        $this->person('toma', $recipient);
        $this->putDps('cLocEmi', $provider['municipality_code'] ?? '');
        foreach (['simple_status' => 'opSimpNac', 'simple_apportionment' => 'regApTribSN', 'special_regime' => 'regEspTrib'] as $source => $tag) {
            $this->putDps('prest/regTrib/' . $tag, $provider[$source] ?? '');
        }
        foreach (['municipality_code' => 'locPrest/cLocPrestacao', 'national_code' => 'cServ/cTribNac', 'municipal_code' => 'cServ/cTribMun', 'nbs' => 'cServ/cNBS', 'description' => 'cServ/xDescServ'] as $source => $tag) {
            $this->putDps('serv/' . $tag, $service[$source] ?? '');
        }
        $this->put('xTribNac', self::scalar($service['national_description'] ?? ''));
        foreach (['service_amount' => 'vServPrest/vServ', 'unconditional_discount' => 'vDescCondIncond/vDescIncond', 'conditional_discount' => 'vDescCondIncond/vDescCond'] as $source => $tag) {
            $this->putDps('valores/' . $tag, self::amount($values[$source] ?? ''));
        }
        foreach (['iss_taxation' => 'tribISSQN', 'retention' => 'tpRetISSQN'] as $source => $tag) {
            $this->putDps('valores/trib/tribMun/' . $tag, $values[$source] ?? '');
        }
        $this->putDps('valores/trib/tribMun/pAliq', self::amount($values['iss_rate'] ?? ''));
        // Local arithmetic stays outside the official NFSe paths. It is only a
        // conference aid, never an authorization result or a municipal assessment.
        try {
            $iss = self::amount($values['service_amount'] ?? '') === '' ? null : TaxEstimate::iss($values);
            if ($iss !== null) {
                $this->put('preview/iss_base', $iss['base']);
                $this->put('preview/iss_rate', $iss['rate']);
                $this->put('preview/iss_amount', $iss['amount']);
            }
        } catch (\Throwable $error) {
            // Incomplete drafts must remain viewable; invalid arithmetic is blank.
        }
        if (($values['total_tax_mode'] ?? '') === 'ibpt') {
            try {
                $estimate = DraftTax::ibpt($draft);
                foreach (['total_federal' => 'vTotTribFed', 'total_state' => 'vTotTribEst', 'total_municipal' => 'vTotTribMun'] as $source => $tag) {
                    $this->putDps('valores/trib/totTrib/vTotTrib/' . $tag, $estimate[$source]);
                }
                $this->putDps('serv/infoCompl/xInfComp', $estimate['text']);
            } catch (\Throwable $error) {
                // Never fall back to manual amounts while IBPT is selected.
                $this->put('preview/ibpt_error', 'Tributos aproximados não calculados. Confira NBS e UF; a vigência vencida, por si só, não impede a emissão.');
            }
        } elseif (($values['total_tax_mode'] ?? '') === 'amount') {
            foreach (['total_federal' => 'vTotTribFed', 'total_state' => 'vTotTribEst', 'total_municipal' => 'vTotTribMun'] as $source => $tag) {
                $this->putDps('valores/trib/totTrib/vTotTrib/' . $tag, self::amount($values[$source] ?? ''));
            }
        } elseif (($values['total_tax_mode'] ?? '') === 'simple') {
            $this->putDps('valores/trib/totTrib/pTotTribSN', self::amount($values['simple_rate'] ?? ''));
        }
        if (self::scalar($ibs['enabled'] ?? '') === '1') {
            foreach (['purpose' => 'finNFSe', 'final_consumer' => 'indFinal', 'operation_indicator' => 'cIndOp', 'destination_indicator' => 'indDest', 'cst' => 'valores/trib/gIBSCBS/CST', 'class_code' => 'valores/trib/gIBSCBS/cClassTrib'] as $source => $tag) {
                $this->putDps('IBSCBS/' . $tag, $ibs[$source] ?? '');
            }
        }
    }

    public function environment(): string { return $this->environment; }
    public function accessKey(): string { return ''; }
    public function number(): string { return ''; }
    public function consultationUrl(): string { return ''; }
    public function text(string $path): string { return $this->fields[$path] ?? ''; }
    public function dpsText(string $path): string { return $this->text('DPS/infDPS/' . $path); }
    public function exists(string $path): bool
    {
        foreach ($this->fields as $field => $value) {
            if ($value !== '' && ($field === $path || strncmp($field, $path . '/', strlen($path) + 1) === 0)) { return true; }
        }
        return false;
    }
    public function dpsExists(string $path): bool { return $this->exists('DPS/infDPS/' . $path); }

    private function person(string $prefix, array $person): void
    {
        $taxId = preg_replace('/[^0-9A-Z]/', '', strtoupper(self::scalar($person['tax_id'] ?? ''))) ?? '';
        if ($taxId !== '') { $this->putDps($prefix . '/' . (strlen($taxId) === 11 ? 'CPF' : 'CNPJ'), $taxId); }
        foreach (['name' => 'xNome', 'municipal_registration' => 'IM', 'phone' => 'fone', 'email' => 'email', 'municipality_code' => 'end/endNac/cMun', 'zip' => 'end/endNac/CEP', 'street' => 'end/xLgr', 'number' => 'end/nro', 'complement' => 'end/xCpl', 'district' => 'end/xBairro'] as $source => $tag) {
            $this->putDps($prefix . '/' . $tag, $person[$source] ?? '');
        }
    }
    private function put(string $path, $value): void { $this->fields[$path] = self::scalar($value); }
    private function putDps(string $path, $value): void { $this->put('DPS/infDPS/' . $path, $value); }
    private static function group(array $draft, string $name): array { return isset($draft[$name]) && is_array($draft[$name]) ? $draft[$name] : []; }
    private static function scalar($value): string { return is_scalar($value) ? trim((string) $value) : ''; }
    private static function amount($value): string
    {
        $value = self::scalar($value);
        if ($value === '') { return ''; }
        try { return Decimal::normalize($value); } catch (\Throwable $error) { return ''; }
    }
}
