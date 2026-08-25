<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

/**
 * Indexed-element scan for user-script AOT loadXML (#19211, #34696).
 *
 * @return list<array{tag: string, id: string, text: string}>
 */
final class DomParseSimpleXmlIdsJitHelper
{
    public static function parseIndexedElementIds(string $xml): array
    {
        $trimmed = trim($xml);
        if ('' === $trimmed || '<' !== $trimmed[0]) {
            return [];
        }

        $elementXml = self::stripDoctype($trimmed);
        $indexed = [];
        $seenIds = [];

        foreach (self::parseDoctypeIdAttributes($trimmed) as $elementName => $idAttr) {
            foreach (self::scanAllIdElements($elementXml, $elementName, $idAttr) as $entry) {
                if (isset($seenIds[$entry['id']])) {
                    // xmlAddID first-wins (#25274 / #34696).
                    continue;
                }
                $seenIds[$entry['id']] = true;
                $indexed[] = $entry;
            }
        }

        foreach (self::scanAllXmlIdElements($elementXml) as $entry) {
            if (isset($seenIds[$entry['id']])) {
                continue;
            }
            $seenIds[$entry['id']] = true;
            $indexed[] = $entry;
        }

        return $indexed;
    }

    /**
     * DTD ATTLIST element → ID attribute qName (#19211 / #34696).
     *
     * @return array<string, string>
     */
    public static function parseDoctypeIdAttributes(string $xml): array
    {
        $idAttrs = [];
        if (!preg_match(
            '/<!DOCTYPE\s+\S+(?:\s+PUBLIC\s+"[^"]*"\s+"[^"]*"|\s+SYSTEM\s+"[^"]*")?\s*\[(.*)\]\s*>/is',
            $xml,
            $doctype
        )) {
            return $idAttrs;
        }
        if (!preg_match_all('/<!ATTLIST\s+(\S+)\s+(\S+)\s+ID\b/', $doctype[1], $matches, PREG_SET_ORDER)) {
            return $idAttrs;
        }
        foreach ($matches as $match) {
            $idAttrs[$match[1]] = $match[2];
        }

        return $idAttrs;
    }

    /**
     * @return list<array{tag: string, id: string, text: string}>
     */
    private static function scanAllIdElements(string $xml, string $tag, string $attr): array
    {
        $out = [];
        $quoted = preg_quote($tag, '/');
        if (!preg_match_all('/<'.$quoted.'(\s[^>]*)>/i', $xml, $matches, PREG_SET_ORDER)) {
            return $out;
        }
        foreach ($matches as $match) {
            $attrs = VmDom::parseMarkupAttributes($match[1] ?? '');
            $value = $attrs[$attr] ?? null;
            if (null === $value || '' === $value) {
                continue;
            }
            $content = DomParseSimpleXmlJitHelper::matchDescendantAttributeArgv(
                $xml,
                $tag,
                $attr,
                $value
            );
            if (null === $content) {
                continue;
            }
            $out[] = [
                'tag' => strtolower($tag),
                'id' => $value,
                'text' => $content[1],
            ];
        }

        return $out;
    }

    /**
     * @return list<array{tag: string, id: string, text: string}>
     */
    private static function scanAllXmlIdElements(string $xml): array
    {
        $out = [];
        if (!preg_match_all(
            '/<([A-Za-z_][\w:.-]*)\b([^>]*\bxml:id="([^"]*)"[^>]*)>/',
            $xml,
            $matches,
            PREG_SET_ORDER
        )) {
            return $out;
        }
        foreach ($matches as $match) {
            $tag = $match[1];
            $value = $match[3];
            if ('' === $value) {
                continue;
            }
            $content = DomParseSimpleXmlJitHelper::matchDescendantAttributeArgv(
                $xml,
                $tag,
                'xml:id',
                $value
            );
            if (null === $content) {
                continue;
            }
            $out[] = [
                'tag' => strtolower($tag),
                'id' => $value,
                'text' => $content[1],
            ];
        }

        return $out;
    }

    private static function stripDoctype(string $xml): string
    {
        if (preg_match('/<!DOCTYPE[^>]*>\s*(.*)$/s', $xml, $match)) {
            return trim($match[1]);
        }

        return $xml;
    }
}
