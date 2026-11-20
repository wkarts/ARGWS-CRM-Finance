<?php
declare(strict_types=1);

namespace NfseNacional;

/** Parses the authorized XML received over the authenticated SEFIN connection.
 * Structural validation is not a certificate-chain or revocation check.
 */
final class NfseDocument
{
    private \DOMDocument $document;
    private \DOMXPath $xpath;
    private string $accessKey;
    private string $dpsId;
    private string $environment;
    private string $number;

    public function __construct(string $xml)
    {
        if ($xml === '' || strlen($xml) > 5242880) {
            throw new \InvalidArgumentException('O XML da NFS-e está vazio ou ultrapassa o limite de 5 MB.');
        }
        $this->document = XmlSigner::loadSafe($xml);
        $root = $this->document->documentElement;
        if (!$root || $root->localName !== 'NFSe' || $root->namespaceURI !== XmlSigner::NFSE_NS) {
            throw new \InvalidArgumentException('A resposta não contém uma NFS-e no padrão nacional.');
        }
        $this->xpath = new \DOMXPath($this->document);
        $this->xpath->registerNamespace('n', XmlSigner::NFSE_NS);
        foreach (['NFSe', 'infNFSe', 'DPS', 'infDPS'] as $name) {
            if ($this->xpath->query('//*[local-name()="' . $name . '"]')->length !== 1) {
                throw new \InvalidArgumentException('O XML contém grupos fiscais ausentes ou duplicados.');
            }
        }
        $infos = $this->xpath->query('/n:NFSe/n:infNFSe');
        $dps = $this->xpath->query('/n:NFSe/n:infNFSe/n:DPS/n:infDPS');
        if ($infos->length !== 1 || $dps->length !== 1) {
            throw new \InvalidArgumentException('A estrutura da NFS-e ou da DPS está inválida.');
        }
        $id = $infos->item(0)->getAttribute('Id');
        if (!preg_match('/^NFS[0-9]{9}[0-9A-Z]{14}[0-9]{27}$/D', $id)) {
            throw new \InvalidArgumentException('A NFS-e não possui uma chave de acesso válida com 50 posições.');
        }
        $this->accessKey = substr($id, 3);
        $this->dpsId = $dps->item(0)->getAttribute('Id');
        if (!preg_match('/^DPS[0-9]{7}(?:1[0-9]{14}|2[0-9A-Z]{14})[0-9]{20}$/D', $this->dpsId)) {
            throw new \InvalidArgumentException('A NFS-e não possui um identificador de DPS válido.');
        }
        $this->environment = $this->dpsText('tpAmb');
        if (!in_array($this->environment, ['1', '2'], true)) {
            throw new \InvalidArgumentException('O ambiente da NFS-e não foi identificado.');
        }
        $this->number = $this->text('nNFSe');
        if (!preg_match('/^[1-9][0-9]{0,12}$/D', $this->number)) {
            throw new \InvalidArgumentException('O número da NFS-e está ausente ou inválido.');
        }
        // TStat from both official schemas: 100=generated, 102=judicial,
        // 103=standalone, 107=MEI. A rejection envelope is never an NFS-e.
        if (!in_array($this->text('cStat'), ['100', '102', '103', '107'], true)) {
            throw new \InvalidArgumentException('O XML não contém uma situação de NFS-e gerada reconhecida pelo leiaute nacional.');
        }
        foreach ([$this->text('dhProc'), $this->dpsText('dhEmi')] as $timestamp) {
            if (!self::validTimestamp($timestamp)) {
                throw new \InvalidArgumentException('A data e hora de geração da NFS-e ou da DPS está inválida.');
            }
        }
        $series = $this->dpsText('serie');
        $dpsNumber = $this->dpsText('nDPS');
        if (!preg_match('/^[0-9]{1,5}$/D', $series) || !preg_match('/^[1-9][0-9]{0,14}$/D', $dpsNumber)
            || substr($this->dpsId, -20) !== str_pad($series, 5, '0', STR_PAD_LEFT) . str_pad($dpsNumber, 15, '0', STR_PAD_LEFT)) {
            throw new \InvalidArgumentException('A série e o número da DPS não correspondem ao identificador retornado.');
        }
    }

    public function accessKey(): string { return $this->accessKey; }
    public function dpsId(): string { return $this->dpsId; }
    public function environment(): string { return $this->environment; }
    public function number(): string { return $this->number; }

    /** Only unambiguous, namespace-qualified scalar paths are accepted. */
    public function text(string $path): string
    {
        $query = $this->path($path);
        $nodes = $this->xpath->query($query);
        if ($nodes->length > 1) {
            throw new \InvalidArgumentException('O XML contém um campo fiscal duplicado.');
        }
        if ($nodes->length === 0) { return ''; }
        if ($nodes->item(0)->getElementsByTagName('*')->length > 0) {
            throw new \InvalidArgumentException('Um campo fiscal textual contém uma estrutura inesperada.');
        }
        return trim((string) $nodes->item(0)->textContent);
    }

    public function dpsText(string $path): string { return $this->text('DPS/infDPS/' . $path); }
    public function exists(string $path): bool { return $this->xpath->query($this->path($path))->length === 1; }
    public function dpsExists(string $path): bool { return $this->exists('DPS/infDPS/' . $path); }

    public function consultationUrl(): string
    {
        $host = $this->environment === '1' ? 'https://www.nfse.gov.br' : 'https://www.producaorestrita.nfse.gov.br';
        return $host . '/ConsultaPublica/?tpc=1&chave=' . rawurlencode($this->accessKey);
    }

    private function path(string $path): string
    {
        if (!preg_match('~^[A-Za-z_][A-Za-z0-9_]*(?:/[A-Za-z_][A-Za-z0-9_]*)*$~D', $path)) {
            throw new \InvalidArgumentException('Caminho XML inválido.');
        }
        return '/n:NFSe/n:infNFSe/n:' . str_replace('/', '/n:', $path);
    }

    private static function validTimestamp(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:[+-]\d{2}:\d{2}|Z)$/D', $value)) { return false; }
        try {
            $date = new \DateTimeImmutable($value);
            return $date->format('Y-m-d\TH:i:s') === substr($value, 0, 19);
        } catch (\Throwable $error) { return false; }
    }
}
