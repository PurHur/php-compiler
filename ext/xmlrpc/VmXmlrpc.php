<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlrpc;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * XML-RPC encode/decode (php-src ext/xmlrpc/xmlrpc-epi-php.c; #6579).
 *
 * PHP-in-PHP value serialization — host DOM only on VM execute path.
 */
final class VmXmlrpc
{
    private static ?string $lastError = null;

    public static function getLastError(): string
    {
        return self::$lastError ?? '';
    }

    public static function clearLastError(): void
    {
        self::$lastError = null;
    }

    public static function encode(Variable $value): string
    {
        self::$lastError = null;
        $inner = self::encodeValue($value->resolveIndirect());

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<param>'."\n"
            .'<value>'.$inner.'</value>'."\n"
            .'</param>';
    }

    /**
     * @return mixed|false
     */
    public static function decode(string $xml)
    {
        self::$lastError = null;
        $xml = trim($xml);
        if ('' === $xml) {
            self::$lastError = 'Invalid XML';

            return false;
        }

        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded) {
            self::$lastError = 'Invalid XML';

            return false;
        }

        $valueNode = self::locateValueElement($doc);
        if (null === $valueNode) {
            self::$lastError = 'Invalid XML-RPC payload';

            return false;
        }

        try {
            return self::decodeValueElement($valueNode);
        } catch (\Throwable) {
            self::$lastError = 'Invalid XML-RPC payload';

            return false;
        }
    }

    private static function encodeValue(Variable $value): string
    {
        switch ($value->type) {
            case Variable::TYPE_NULL:
                return '<string></string>';
            case Variable::TYPE_BOOLEAN:
                return '<boolean>'.($value->toBool(null) ? '1' : '0').'</boolean>';
            case Variable::TYPE_INTEGER:
                return '<int>'.$value->toInt(null).'</int>';
            case Variable::TYPE_FLOAT:
                return '<double>'.self::formatDouble($value->toFloat(null)).'</double>';
            case Variable::TYPE_STRING:
                return '<string>'.self::escapeXml($value->toString(null)).'</string>';
            case Variable::TYPE_ARRAY:
                return self::encodeArray($value->toArray());
            default:
                throw new \Exception('Cannot xmlrpc_encode() value of type '.$value->type);
        }
    }

    private static function encodeArray(HashTable $table): string
    {
        $pairs = iterator_to_array($table->iterateKeyed(true), false);
        if ($table->isPackedList()) {
            $out = '<array><data>';
            foreach ($pairs as [, $element]) {
                $out .= '<value>'.self::encodeValue($element).'</value>';
            }

            return $out.'</data></array>';
        }

        $out = '<struct>';
        foreach ($pairs as [$key, $element]) {
            $name = self::arrayKeyToString($key->resolveIndirect());
            $out .= '<member><name>'.self::escapeXml($name).'</name><value>'
                .self::encodeValue($element).'</value></member>';
        }

        return $out.'</struct>';
    }

    private static function arrayKeyToString(Variable $key): string
    {
        return match ($key->type) {
            Variable::TYPE_STRING => $key->toString(null),
            Variable::TYPE_INTEGER => (string) $key->toInt(null),
            default => (string) $key->toString(null),
        };
    }

    private static function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function formatDouble(float $value): string
    {
        if (is_nan($value)) {
            return 'NAN';
        }
        if ($value === INF) {
            return 'INF';
        }
        if ($value === -INF) {
            return '-INF';
        }

        return rtrim(rtrim(sprintf('%.17F', $value), '0'), '.');
    }

    private static function locateValueElement(\DOMDocument $doc): ?\DOMElement
    {
        $root = $doc->documentElement;
        if (null === $root) {
            return null;
        }
        if ('value' === strtolower($root->localName ?? $root->nodeName)) {
            return $root;
        }
        if ('param' === strtolower($root->localName ?? $root->nodeName)) {
            foreach ($root->childNodes as $child) {
                if ($child instanceof \DOMElement && 'value' === strtolower($child->localName ?? $child->nodeName)) {
                    return $child;
                }
            }
        }
        $values = $doc->getElementsByTagName('value');
        if ($values->length > 0 && $values->item(0) instanceof \DOMElement) {
            return $values->item(0);
        }

        return null;
    }

    /**
     * @return mixed
     */
    private static function decodeValueElement(\DOMElement $valueNode)
    {
        $typed = self::firstElementChild($valueNode);
        if (null === $typed) {
            return trim($valueNode->textContent ?? '');
        }

        $tag = strtolower($typed->localName ?? $typed->nodeName);
        return match ($tag) {
            'int', 'i4', 'i8' => (int) trim($typed->textContent ?? '0'),
            'boolean', 'bool' => '1' === trim($typed->textContent ?? '0') || 'true' === strtolower(trim($typed->textContent ?? '')),
            'double', 'float' => self::parseDouble(trim($typed->textContent ?? '0')),
            'string' => (string) ($typed->textContent ?? ''),
            'base64' => base64_decode(trim($typed->textContent ?? ''), true) ?: '',
            'array' => self::decodeArrayElement($typed),
            'struct' => self::decodeStructElement($typed),
            default => trim($typed->textContent ?? ''),
        };
    }

    /**
     * @return list<mixed>
     */
    private static function decodeArrayElement(\DOMElement $arrayNode): array
    {
        $data = self::firstChildByName($arrayNode, 'data');
        if (null === $data) {
            return [];
        }
        $out = [];
        foreach ($data->childNodes as $child) {
            if ($child instanceof \DOMElement && 'value' === strtolower($child->localName ?? $child->nodeName)) {
                $out[] = self::decodeValueElement($child);
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeStructElement(\DOMElement $structNode): array
    {
        $out = [];
        foreach ($structNode->childNodes as $child) {
            if (!$child instanceof \DOMElement || 'member' !== strtolower($child->localName ?? $child->nodeName)) {
                continue;
            }
            $nameNode = self::firstChildByName($child, 'name');
            $valueNode = self::firstChildByName($child, 'value');
            if (null === $nameNode || null === $valueNode) {
                continue;
            }
            $out[trim($nameNode->textContent ?? '')] = self::decodeValueElement($valueNode);
        }

        return $out;
    }

    private static function firstElementChild(\DOMElement $node): ?\DOMElement
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                return $child;
            }
        }

        return null;
    }

    private static function firstChildByName(\DOMElement $node, string $name): ?\DOMElement
    {
        $want = strtolower($name);
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && $want === strtolower($child->localName ?? $child->nodeName)) {
                return $child;
            }
        }

        return null;
    }

    private static function parseDouble(string $raw): float
    {
        if ('NAN' === strtoupper($raw)) {
            return NAN;
        }
        if ('INF' === strtoupper($raw)) {
            return INF;
        }
        if ('-INF' === strtoupper($raw)) {
            return -INF;
        }

        return (float) $raw;
    }
}
