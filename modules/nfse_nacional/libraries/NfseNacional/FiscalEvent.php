<?php
declare(strict_types=1);

namespace NfseNacional;

/** A registered event received over the authenticated SEFIN connection.
 * This is structural/correlation validation, not a certificate-chain check.
 * A pedRegEvento request alone never confirms cancellation.
 */
final class FiscalEvent
{
    private const CANCELLATION_TYPES = ['101101', '105102', '105104', '305101'];

    public static function fromXml(string $xml, string $accessKey, string $environment): array
    {
        if ($xml === '' || strlen($xml) > 5242880 || !preg_match('/^[0-9A-Z]{50}$/D', $accessKey)
            || !in_array($environment, ['1', '2'], true)) {
            throw new FiscalEventException('O evento fiscal ou sua identificação está inválido.');
        }
        $document = XmlSigner::loadSafe($xml);
        $root = $document->documentElement;
        if (!$root || $root->localName !== 'evento' || $root->namespaceURI !== XmlSigner::NFSE_NS) {
            throw new FiscalEventException('A resposta não contém um evento fiscal registrado.');
        }
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('n', XmlSigner::NFSE_NS);
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        foreach (['evento', 'infEvento', 'pedRegEvento', 'infPedReg'] as $name) {
            if ($xpath->query('//*[local-name()="' . $name . '"]')->length !== 1) {
                throw new FiscalEventException('O evento contém grupos fiscais ausentes ou duplicados.');
            }
        }
        $info = $xpath->query('/n:evento/n:infEvento');
        $request = $xpath->query('/n:evento/n:infEvento/n:pedRegEvento/n:infPedReg');
        if ($info->length !== 1 || $request->length !== 1
            || $xpath->query('/n:evento/ds:Signature')->length !== 1) {
            throw new FiscalEventException('A resposta não contém a estrutura de um evento registrado.');
        }
        $value = static function (string $path) use ($xpath): string {
            $nodes = $xpath->query('/n:evento/n:infEvento/n:' . str_replace('/', '/n:', $path));
            if ($nodes->length !== 1 || $nodes->item(0)->getElementsByTagName('*')->length > 0) {
                throw new FiscalEventException('Campo obrigatório do evento ausente ou duplicado: ' . $path . '.');
            }
            return trim((string) $nodes->item(0)->textContent);
        };
        $id = $info->item(0)->getAttribute('Id');
        if (!preg_match('/^EVT([0-9A-Z]{50})([0-9]{6})([0-9]{3})$/D', $id, $parts)
            || !hash_equals($accessKey, $parts[1])) {
            throw new FiscalEventException('O identificador do evento não corresponde à NFS-e consultada.');
        }
        $type = $parts[2];
        $sequence = $value('nSeqEvento');
        if (!preg_match('/^[0-9]{1,3}$/D', $sequence) || (int) $sequence < 1
            || str_pad($sequence, 3, '0', STR_PAD_LEFT) !== $parts[3]) {
            throw new FiscalEventException('O sequencial do evento difere do seu identificador.');
        }
        $prefix = 'pedRegEvento/infPedReg/';
        if (!hash_equals($accessKey, $value($prefix . 'chNFSe')) || $value($prefix . 'tpAmb') !== $environment) {
            throw new FiscalEventException('O evento não corresponde à chave ou ao ambiente consultado.');
        }
        $requestId = $request->item(0)->getAttribute('Id');
        if (!in_array($requestId, ['PRE' . $accessKey . $type, 'PRE' . $accessKey . $type . $parts[3]], true)) {
            throw new FiscalEventException('O pedido de registro não corresponde ao evento retornado.');
        }
        $payloads = [];
        foreach ($request->item(0)->childNodes as $child) {
            if ($child instanceof \DOMElement && preg_match('/^e[0-9]{6}$/D', $child->localName)) { $payloads[] = $child; }
        }
        if (count($payloads) !== 1 || $payloads[0]->namespaceURI !== XmlSigner::NFSE_NS || $payloads[0]->localName !== 'e' . $type) {
            throw new FiscalEventException('O tipo de evento difere do conteúdo registrado.');
        }
        $processed = $value('dhProc');
        if (!self::validTimestamp($processed) || !self::validTimestamp($value($prefix . 'dhEvento'))) {
            throw new FiscalEventException('A data do evento registrado está inválida.');
        }
        // Historical official TCInfEvento uses nDFe; newer schemas renamed it nDFSe.
        // Require exactly one direct, namespace-qualified field, including across aliases.
        $recordFields = $xpath->query('/n:evento/n:infEvento/n:nDFSe | /n:evento/n:infEvento/n:nDFe');
        if ($recordFields->length !== 1) {
            throw new FiscalEventException('O número do registro do evento está ausente ou duplicado (nDFSe/nDFe).');
        }
        $recordNumber = $value($recordFields->item(0)->localName);
        if (!preg_match('/^[0-9]{1,13}$/D', $recordNumber)
            || !in_array($value('ambGer'), ['1', '2', '3'], true)) {
            throw new FiscalEventException('O registro fiscal do evento está inválido.');
        }
        if ($type === '105104' && $value($prefix . 'e105104/cMotivo') !== '1') {
            throw new FiscalEventException('O evento não confirma o deferimento do cancelamento.');
        }
        if (in_array($type, self::CANCELLATION_TYPES, true) && (int) $sequence !== 1) {
            throw new FiscalEventException('O sequencial do cancelamento está inválido.');
        }
        return [
            'id' => $id, 'type' => $type, 'sequence' => (int) $sequence,
            'access_key' => $accessKey, 'environment' => $environment,
            'processed_at' => $processed, 'record_number' => $recordNumber,
            'cancellation' => in_array($type, self::CANCELLATION_TYPES, true),
            // nDFSe and EVT are record identifiers, never an invented NF-e protocol.
            'protocol' => '', 'xml' => $xml,
        ];
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
