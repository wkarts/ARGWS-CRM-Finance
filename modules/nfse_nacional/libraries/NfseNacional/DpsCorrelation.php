<?php
declare(strict_types=1);

namespace NfseNacional;

/** Correlates the submitted fiscal content, not only the reusable series/number identity. */
final class DpsCorrelation
{
    public const MISMATCH = 'DPS já associada a dados diferentes; revise a série utilizada por outros emissores.';

    /**
     * The registry may enrich fields that were absent from the submitted DPS.
     * Every submitted element, attribute and scalar value must still be present
     * unchanged. XML prefixes and formatting whitespace do not affect matching.
     * Repeated paths and foreign namespaces are rejected, never selected by position.
     */
    public static function assertMatches(string $expectedDpsXml, string $authorizedNfseXml): void
    {
        $expected = self::information($expectedDpsXml, false);
        $returned = self::information($authorizedNfseXml, true);
        $expectedFields = []; $returnedFields = [];
        self::collect($expected, '/infDPS', $expectedFields);
        self::collect($returned, '/infDPS', $returnedFields);

        foreach ($expectedFields as $path => $field) {
            if (!isset($returnedFields[$path])) { self::mismatch(); }
            $actual = $returnedFields[$path];
            foreach ($field['attributes'] as $name => $value) {
                if (!array_key_exists($name, $actual['attributes']) || $actual['attributes'][$name] !== $value) { self::mismatch(); }
            }
            if ($field['value'] !== null && ($actual['has_children'] || $field['value'] !== $actual['value'])) { self::mismatch(); }
        }
    }

    private static function information(string $xml, bool $authorized): \DOMElement
    {
        $doc = XmlSigner::loadSafe($xml);
        $rootName = $authorized ? 'NFSe' : 'DPS';
        if (!$doc->documentElement || $doc->documentElement->namespaceURI !== XmlSigner::NFSE_NS
            || $doc->documentElement->localName !== $rootName) { self::mismatch(); }
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('n', XmlSigner::NFSE_NS);
        // Includes other namespaces in the ambiguity check, so an alternate subtree
        // cannot hide behind the namespace-qualified path used for extraction.
        if ($xp->query('//*[local-name()="infDPS"]')->length !== 1) { self::mismatch(); }
        $path = $authorized ? '/n:NFSe/n:infNFSe/n:DPS/n:infDPS' : '/n:DPS/n:infDPS';
        $nodes = $xp->query($path);
        if ($nodes->length !== 1) { self::mismatch(); }
        return $nodes->item(0);
    }

    private static function collect(\DOMElement $element, string $path, array &$fields, int $depth = 0): void
    {
        if ($depth > 64 || count($fields) >= 4096 || isset($fields[$path]) || $element->namespaceURI !== XmlSigner::NFSE_NS) { self::mismatch(); }
        $attributes = [];
        foreach ($element->attributes as $attribute) {
            if ($attribute->namespaceURI === 'http://www.w3.org/2000/xmlns/') { continue; }
            if ($attribute->namespaceURI !== null && $attribute->namespaceURI !== '') { self::mismatch(); }
            $attributes[$attribute->localName] = $attribute->value;
        }
        $children = [];
        $text = '';
        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMElement) { $children[] = $child; }
            elseif ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) { $text .= $child->nodeValue; }
            elseif ($child->nodeType !== XML_COMMENT_NODE) { self::mismatch(); }
        }
        $text = trim($text);
        if ($children && $text !== '') { self::mismatch(); }
        // An empty complex group such as gIBSCBS establishes presence, not a scalar.
        $fields[$path] = ['attributes' => $attributes, 'value' => $text === '' ? null : $text, 'has_children' => (bool) $children];
        foreach ($children as $child) { self::collect($child, $path . '/' . $child->localName, $fields, $depth + 1); }
    }

    private static function mismatch(): void
    {
        throw new \UnexpectedValueException(self::MISMATCH);
    }
}
