<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

/**
 * Preg-free indexed-element scan for user-script AOT loadXML (#19211).
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

        foreach (self::parseDoctypeIdAttributes($trimmed) as $elementName => $idAttr) {
            $value = self::scanFirstAttributeValue($elementXml, $elementName, $idAttr);
            if (null === $value || '' === $value) {
                continue;
            }
            $match = DomParseSimpleXmlJitHelper::matchDescendantAttributeArgv(
                $elementXml,
                $elementName,
                $idAttr,
                $value
            );
            if (null === $match) {
                continue;
            }
            $indexed[] = [
                'tag' => strtolower($elementName),
                'id' => $value,
                'text' => $match[1],
            ];
        }

        $xmlId = self::scanFirstXmlIdValue($elementXml);
        $tag = self::scanFirstElementWithXmlId($elementXml);
        if (null !== $xmlId && '' !== $xmlId && null !== $tag) {
            $match = DomParseSimpleXmlJitHelper::matchDescendantAttributeArgv(
                $elementXml,
                $tag,
                'xml:id',
                $xmlId
            );
            if (null !== $match) {
                $indexed[] = [
                    'tag' => strtolower($tag),
                    'id' => $xmlId,
                    'text' => $match[1],
                ];
            }
        }

        return $indexed;
    }

    /**
     * @return array<string, string>
     */
    private static function parseDoctypeIdAttributes(string $xml): array
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

    private static function stripDoctype(string $xml): string
    {
        if (preg_match('/<!DOCTYPE[^>]*>\s*(.*)$/s', $xml, $match)) {
            return trim($match[1]);
        }

        return $xml;
    }

    private static function scanFirstAttributeValue(string $xml, string $tag, string $attr): ?string
    {
        if (!preg_match('/<'.$tag.'(\s[^>]*)>/i', $xml, $match)) {
            return null;
        }
        $attrs = VmDom::parseMarkupAttributes($match[1] ?? '');

        return $attrs[$attr] ?? null;
    }

    private static function scanFirstXmlIdValue(string $xml): ?string
    {
        if (preg_match('/\bxml:id="([^"]*)"/', $xml, $match)) {
            return $match[1];
        }

        return null;
    }

    private static function scanFirstElementWithXmlId(string $xml): ?string
    {
        if (preg_match('/<([A-Za-z_][\w:.-]*)\b[^>]*\bxml:id="/', $xml, $match)) {
            return $match[1];
        }

        return null;
    }
}
