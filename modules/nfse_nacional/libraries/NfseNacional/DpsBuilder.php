<?php
declare(strict_types=1);

namespace NfseNacional;

use DOMDocument;
use DOMElement;

/** Domestic, regular service DPS. Version selection follows the environment's published XSD. */
final class DpsBuilder
{
    public const NS = 'http://www.sped.fazenda.gov.br/nfse';

    public static function identifier(array $provider, string $series, string $number): string
    {
        $taxId = preg_replace('/[^0-9A-Z]/', '', strtoupper((string) ($provider['tax_id'] ?? ''))) ?? '';
        $municipality = (string) ($provider['municipality_code'] ?? '');
        if (!preg_match('/^[0-9]{7}$/D', $municipality)
            || !preg_match('/^(?:[0-9]{11}|[0-9A-Z]{12}[0-9]{2})$/D', $taxId)
            || !preg_match('/^[0-9]{1,5}$/D', $series) || (int) $series < 1 || (int) $series > 49999
            || !preg_match('/^[1-9][0-9]{0,14}$/D', $number)) {
            throw new \InvalidArgumentException('Identificação da DPS inválida: confira prestador, município, série (1 a 49999) e número.');
        }
        return 'DPS' . $municipality . (strlen($taxId) === 11 ? '1' : '2')
            . str_pad($taxId, 14, '0', STR_PAD_LEFT) . str_pad($series, 5, '0', STR_PAD_LEFT)
            . str_pad($number, 15, '0', STR_PAD_LEFT);
    }

    public function build(array $draft, string $series, string $number): string
    {
        $environment = (string) ($draft['environment'] ?? '');
        if (!in_array($environment, ['1', '2'], true)) { throw new \InvalidArgumentException('Ambiente fiscal inválido.'); }
        $provider = $draft['provider'] ?? [];
        $recipient = $draft['recipient'] ?? [];
        $service = $draft['service'] ?? [];
        $values = $draft['values'] ?? [];
        $ibs = $draft['ibscbs'] ?? [];
        $mode = (string) ($values['total_tax_mode'] ?? 'none');
        // IBPT values remain correlated with issuer UF/NBS and structurally validated even after expiration.
        // Recompute the amounts here; posted/manual total fields are not evidence.
        $ibpt = $mode === 'ibpt' ? DraftTax::ibpt($draft) : null;
        if ((string) ($ibs['purpose'] ?? '0') !== '0') {
            throw new \InvalidArgumentException('Esta versão emite NFS-e regular. Notas de ajuste de crédito/débito exigem fluxo próprio.');
        }
        if (!in_array((string) ($values['iss_taxation'] ?? '1'), ['1', '4'], true)) {
            throw new \InvalidArgumentException('Esta versão atende operação tributável ou não incidência. Imunidade/exportação exigem informações fiscais adicionais.');
        }
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = false;
        $root = $doc->createElementNS(self::NS, 'DPS');
        $root->setAttribute('versao', '1.01');
        $doc->appendChild($root);
        $info = self::node($doc, $root, 'infDPS');
        $info->setAttribute('Id', self::identifier($provider, $series, $number));
        self::node($doc, $info, 'tpAmb', $environment);
        self::node($doc, $info, 'dhEmi', self::required($draft, 'issued_at'));
        self::node($doc, $info, 'verAplic', 'PerfexNfse_0.1.0');
        self::node($doc, $info, 'serie', (string) ((int) $series));
        self::node($doc, $info, 'nDPS', $number);
        self::node($doc, $info, 'dCompet', self::required($draft, 'competence'));
        self::node($doc, $info, 'tpEmit', '1');
        if ($environment === '2') { self::node($doc, $info, 'finNFSe', '0'); }
        self::node($doc, $info, 'cLocEmi', self::required($provider, 'municipality_code'));
        if (!empty($draft['substitution'])) {
            $replacement = Substitution::parameters($draft['substitution']);
            $subst = self::node($doc, $info, 'subst');
            self::node($doc, $subst, 'chSubstda', $replacement['access_key']);
            self::node($doc, $subst, 'cMotivo', $replacement['reason_code']);
            self::node($doc, $subst, 'xMotivo', $replacement['reason']);
        }
        $prest = self::node($doc, $info, 'prest');
        self::taxId($doc, $prest, self::required($provider, 'tax_id'));
        self::optional($doc, $prest, 'IM', $provider['municipal_registration'] ?? '');
        // The national registry supplies issuer name/address when tpEmit = 1.
        self::optional($doc, $prest, 'fone', preg_replace('/[^0-9]/', '', (string) ($provider['phone'] ?? '')) ?? '');
        self::optional($doc, $prest, 'email', $provider['email'] ?? '');
        $reg = self::node($doc, $prest, 'regTrib');
        self::node($doc, $reg, 'opSimpNac', self::required($provider, 'simple_status'));
        self::optional($doc, $reg, 'regApTribSN', $provider['simple_apportionment'] ?? '');
        self::node($doc, $reg, 'regEspTrib', (string) ($provider['special_regime'] ?? '0'));
        $toma = self::node($doc, $info, 'toma');
        self::taxId($doc, $toma, self::required($recipient, 'tax_id'));
        self::node($doc, $toma, 'xNome', self::required($recipient, 'name'));
        $end = self::node($doc, $toma, 'end');
        $national = self::node($doc, $end, 'endNac');
        self::node($doc, $national, 'cMun', self::required($recipient, 'municipality_code'));
        self::node($doc, $national, 'CEP', preg_replace('/[^0-9]/', '', self::required($recipient, 'zip')) ?? '');
        self::node($doc, $end, 'xLgr', self::required($recipient, 'street'));
        self::node($doc, $end, 'nro', self::required($recipient, 'number'));
        self::optional($doc, $end, 'xCpl', $recipient['complement'] ?? '');
        self::node($doc, $end, 'xBairro', self::required($recipient, 'district'));
        self::optional($doc, $toma, 'fone', preg_replace('/[^0-9]/', '', (string) ($recipient['phone'] ?? '')) ?? '');
        self::optional($doc, $toma, 'email', $recipient['email'] ?? '');
        $serv = self::node($doc, $info, 'serv');
        $location = self::node($doc, $serv, 'locPrest');
        self::node($doc, $location, 'cLocPrestacao', self::required($service, 'municipality_code'));
        $classification = self::node($doc, $serv, 'cServ');
        self::node($doc, $classification, 'cTribNac', self::required($service, 'national_code'));
        self::optional($doc, $classification, 'cTribMun', $service['municipal_code'] ?? '');
        if ($environment === '2') { self::optional($doc, $classification, 'cNBS', $service['nbs'] ?? ''); }
        self::node($doc, $classification, 'xDescServ', self::required($service, 'description'));
        if ($environment === '1') { self::optional($doc, $classification, 'cNBS', $service['nbs'] ?? ''); }
        if ($ibpt !== null) {
            $information = (string) $ibpt['text'];
            if ($information === '' || mb_strlen($information, 'UTF-8') > 2000) {
                throw new \InvalidArgumentException('A informação dos tributos aproximados não atende ao limite da DPS.');
            }
            $extra = self::node($doc, $serv, 'infoCompl');
            self::node($doc, $extra, 'xInfComp', $information);
        }
        $money = self::node($doc, $info, 'valores');
        $serviceValues = self::node($doc, $money, 'vServPrest');
        self::node($doc, $serviceValues, 'vServ', self::decimal(self::required($values, 'service_amount')));
        $unconditional = self::decimal((string) ($values['unconditional_discount'] ?? '0.00'));
        $conditional = self::decimal((string) ($values['conditional_discount'] ?? '0.00'));
        if ($unconditional !== '0.00' || $conditional !== '0.00') {
            $discounts = self::node($doc, $money, 'vDescCondIncond');
            if ($unconditional !== '0.00') { self::node($doc, $discounts, 'vDescIncond', $unconditional); }
            if ($conditional !== '0.00') { self::node($doc, $discounts, 'vDescCond', $conditional); }
        }
        $tax = self::node($doc, $money, 'trib');
        $municipal = self::node($doc, $tax, 'tribMun');
        self::node($doc, $municipal, 'tribISSQN', (string) ($values['iss_taxation'] ?? '1'));
        self::node($doc, $municipal, 'tpRetISSQN', (string) ($values['retention'] ?? '1'));
        if (isset($values['iss_rate']) && $values['iss_rate'] !== '' && self::canDeclareIssRate($provider, $values)) {
            self::node($doc, $municipal, 'pAliq', self::decimal((string) $values['iss_rate']));
        }
        $total = self::node($doc, $tax, 'totTrib');
        if ($mode === 'amount' || $mode === 'ibpt') {
            $amounts = self::node($doc, $total, 'vTotTrib');
            foreach (['vTotTribFed' => 'total_federal', 'vTotTribEst' => 'total_state', 'vTotTribMun' => 'total_municipal'] as $tag => $key) {
                self::node($doc, $amounts, $tag, self::decimal((string) ($ibpt !== null ? $ibpt[$key] : ($values[$key] ?? '0.00'))));
            }
        } elseif ($mode === 'simple') {
            self::node($doc, $total, 'pTotTribSN', self::decimal(self::required($values, 'simple_rate')));
        } elseif ($mode === 'none') {
            self::node($doc, $total, 'indTotTrib', '0');
        } else { throw new \InvalidArgumentException('Modo de totalização dos tributos inválido.'); }
        if ((string) ($ibs['enabled'] ?? '0') === '1') { self::ibscbs($doc, $info, $ibs, $environment); }
        return (string) $doc->saveXML();
    }

    public function schemaPath(string $environment): string
    {
        if (!in_array($environment, ['1', '2'], true)) { throw new \InvalidArgumentException('Ambiente fiscal inválido.'); }
        return dirname(__DIR__, 2) . '/schemas/' . ($environment === '1' ? '20260209' : '20261020') . '/DPS_v1.01.xsd';
    }

    /** Errors are returned as user-readable strings; an unavailable schema never passes validation. */
    public function validateSchema(string $xml, string $environment): array
    {
        $path = $this->schemaPath($environment);
        if (!is_file($path)) { return ['O esquema XSD do ambiente não está instalado.']; }
        try { $doc = XmlSigner::loadSafe($xml); } catch (\Throwable $e) { return [$e->getMessage()]; }
        $previous = libxml_use_internal_errors(true);
        $loader = function_exists('libxml_get_external_entity_loader') ? libxml_get_external_entity_loader() : null;
        $schemaDirectory = realpath(dirname($path));
        libxml_set_external_entity_loader(static function ($public, $system, $context) use ($schemaDirectory) {
            // Only bundled schemas may be resolved, including imports. Never resolve a network URL or DTD.
            if (!is_string($system) || preg_match('#^[a-z]+://#i', $system) || strtolower(pathinfo($system, PATHINFO_EXTENSION)) !== 'xsd') { return null; }
            $candidate = realpath($system);
            if (!$candidate) { $candidate = realpath($schemaDirectory . '/' . basename($system)); }
            if (!$candidate || dirname($candidate) !== $schemaDirectory) { return null; }
            return fopen($candidate, 'rb');
        });
        libxml_clear_errors();
        try {
            $valid = $doc->schemaValidate($path);
            $errors = [];
            if (!$valid) {
                foreach (libxml_get_errors() as $error) {
                    $message = trim((string) $error->message);
                    $message = str_replace($schemaDirectory, '[esquemas]', $message);
                    $errors[] = $message;
                    if (count($errors) >= 10) { break; }
                }
            }
            return $valid ? [] : ($errors ?: ['Falha ao validar a DPS no esquema XSD.']);
        } finally {
            libxml_clear_errors();
            libxml_set_external_entity_loader($loader);
            libxml_use_internal_errors($previous);
        }
    }

    private static function ibscbs(DOMDocument $doc, DOMElement $info, array $ibs, string $environment): void
    {
        if ((string) ($ibs['destination_indicator'] ?? '0') !== '0') {
            throw new \InvalidArgumentException('Nesta versão o destinatário IBS/CBS deve ser o próprio tomador selecionado. Destinatário distinto requer grupo específico.');
        }
        $group = self::node($doc, $info, 'IBSCBS');
        if ($environment === '1') { self::node($doc, $group, 'finNFSe', '0'); }
        self::optional($doc, $group, 'indFinal', $ibs['final_consumer'] ?? '');
        self::node($doc, $group, 'cIndOp', self::required($ibs, 'operation_indicator'));
        self::node($doc, $group, 'indDest', '0');
        $values = self::node($doc, $group, 'valores');
        $tax = self::node($doc, $values, 'trib');
        $cst = self::required($ibs, 'cst');
        $classification = self::required($ibs, 'class_code');
        if ($environment === '1') {
            $detail = self::node($doc, $tax, 'gIBSCBS');
            self::node($doc, $detail, 'CST', $cst);
            self::node($doc, $detail, 'cClassTrib', $classification);
        } else {
            self::node($doc, $tax, 'CST', $cst);
            self::node($doc, $tax, 'cClassTrib', $classification);
            if (!in_array($cst, ['400', '410', '820'], true)) { self::node($doc, $tax, 'gIBSCBS'); }
        }
    }

    private static function taxId(DOMDocument $doc, DOMElement $parent, string $value): void
    {
        $id = preg_replace('/[^0-9A-Z]/', '', strtoupper($value)) ?? '';
        self::node($doc, $parent, strlen($id) === 11 ? 'CPF' : 'CNPJ', $id);
    }

    /**
     * A configured rate may be useful for the local preview but forbidden in DPS.
     * These exclusions do not depend on a municipality agreement lookup:
     * E0600 (MEI), E0602 (non-taxable), E0604 (special regime), E0625/E0631
     * (ME/EPP with ISS in Simples and no retention). Other municipal rules remain
     * subject to national validation; this method does not infer an agreement.
     */
    private static function canDeclareIssRate(array $provider, array $values): bool
    {
        if ((string) ($provider['simple_status'] ?? '') === '2'
            || (string) ($values['iss_taxation'] ?? '1') !== '1'
            || (string) ($provider['special_regime'] ?? '0') !== '0') { return false; }
        return !((string) ($provider['simple_status'] ?? '') === '3'
            && (string) ($provider['simple_apportionment'] ?? '') === '1'
            && (string) ($values['retention'] ?? '1') === '1');
    }

    private static function decimal(string $value): string
    {
        if (!preg_match('/^[0-9]{1,15}(?:\.[0-9]{1,2})?$/D', $value)) {
            throw new \InvalidArgumentException('Valor fiscal inválido. Utilize decimal com ponto e até duas casas.');
        }
        $parts = explode('.', $value, 2);
        return (ltrim($parts[0], '0') ?: '0') . '.' . str_pad($parts[1] ?? '', 2, '0');
    }

    private static function required(array $data, string $key): string
    {
        $value = trim((string) ($data[$key] ?? ''));
        if ($value === '') { throw new \InvalidArgumentException('Campo obrigatório ausente na DPS: ' . $key . '.'); }
        return $value;
    }

    private static function optional(DOMDocument $doc, DOMElement $parent, string $name, $value): void
    {
        $value = trim((string) $value);
        if ($value !== '') { self::node($doc, $parent, $name, $value); }
    }

    private static function node(DOMDocument $doc, DOMElement $parent, string $name, ?string $value = null): DOMElement
    {
        $el = $doc->createElementNS(self::NS, $name);
        if ($value !== null) { $el->appendChild($doc->createTextNode($value)); }
        $parent->appendChild($el);
        return $el;
    }
}
