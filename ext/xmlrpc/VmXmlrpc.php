<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlrpc;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * XML-RPC encode/decode (php-src ext/xmlrpc/xmlrpc-epi-php.c; #6579).
 *
 * PHP-in-PHP value serialization — string XML parser (no host DOMDocument; #19048 AOT).
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
        if ('' === $xml || !str_contains($xml, '<')) {
            self::$lastError = 'Invalid XML';

            return false;
        }

        $valueInner = self::extractBalancedElementInner($xml, 'value');
        if (null === $valueInner) {
            self::$lastError = 'Invalid XML';

            return false;
        }

        try {
            return self::decodeValueString($valueInner);
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
        return str_replace(
            ['&', '<', '>', '"', "'"],
            ['&amp;', '&lt;', '&gt;', '&quot;', '&apos;'],
            $value
        );
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

    private static function extractBalancedElementInner(string $xml, string $tag): ?string
    {
        $tagPattern = preg_quote($tag, '/');
        if (!preg_match('/<'.$tagPattern.'(\s[^>]*)?>/i', $xml, $open, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $innerStart = $open[0][1] + \strlen($open[0][0]);
        $depth = 1;
        $pos = $innerStart;
        $closePattern = '/<(\/?)'.$tagPattern.'(\s[^>]*)?>/i';
        while ($depth > 0 && preg_match($closePattern, $xml, $match, PREG_OFFSET_CAPTURE, $pos)) {
            $isClose = '' !== $match[1][0];
            $pos = $match[0][1] + \strlen($match[0][0]);
            if ($isClose) {
                --$depth;
                if (0 === $depth) {
                    return \substr($xml, $innerStart, $match[0][1] - $innerStart);
                }
            } else {
                ++$depth;
            }
        }

        return null;
    }

    /**
     * @return mixed
     */
    private static function decodeValueString(string $valueInner)
    {
        $valueInner = trim($valueInner);
        if ('' === $valueInner) {
            return '';
        }
        if ('<' !== $valueInner[0]) {
            return $valueInner;
        }
        if (!preg_match('/^<([a-zA-Z0-9]+)/', $valueInner, $tagMatch)) {
            return $valueInner;
        }
        $tag = strtolower($tagMatch[1]);
        $typedInner = self::extractBalancedElementInner($valueInner, $tag);
        if (null === $typedInner) {
            return trim($valueInner);
        }
        $typedInner = trim($typedInner);

        return match ($tag) {
            'int', 'i4', 'i8' => (int) $typedInner,
            'boolean', 'bool' => '1' === $typedInner || 'true' === strtolower($typedInner),
            'double', 'float' => self::parseDouble($typedInner),
            'string' => $typedInner,
            'base64' => self::decodeBase64($typedInner),
            'array' => self::decodeArrayString($typedInner),
            'struct' => self::decodeStructString($typedInner),
            default => $typedInner,
        };
    }

    /**
     * @return list<mixed>
     */
    private static function decodeArrayString(string $arrayInner): array
    {
        $dataInner = self::extractBalancedElementInner($arrayInner, 'data');
        if (null === $dataInner) {
            return [];
        }
        $out = [];
        $pos = 0;
        $dataLen = \strlen($dataInner);
        while ($pos < $dataLen) {
            if (!preg_match('/<value(\s[^>]*)?>/i', $dataInner, $match, PREG_OFFSET_CAPTURE, $pos)) {
                break;
            }
            $sliceStart = $match[0][1];
            $valueInner = self::extractBalancedElementInner(\substr($dataInner, $sliceStart), 'value');
            if (null === $valueInner) {
                break;
            }
            $out[] = self::decodeValueString($valueInner);
            $closePos = stripos($dataInner, '</value>', $sliceStart);
            if (false === $closePos) {
                break;
            }
            $pos = $closePos + 8;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeStructString(string $structInner): array
    {
        $out = [];
        $pos = 0;
        $structLen = \strlen($structInner);
        while ($pos < $structLen) {
            if (!preg_match('/<member(\s[^>]*)?>/i', $structInner, $memberOpen, PREG_OFFSET_CAPTURE, $pos)) {
                break;
            }
            $sliceStart = $memberOpen[0][1];
            $memberInner = self::extractBalancedElementInner(\substr($structInner, $sliceStart), 'member');
            if (null === $memberInner) {
                break;
            }
            $nameInner = self::extractBalancedElementInner($memberInner, 'name');
            $valueInner = self::extractBalancedElementInner($memberInner, 'value');
            if (null !== $nameInner && null !== $valueInner) {
                $out[trim($nameInner)] = self::decodeValueString($valueInner);
            }
            $closePos = stripos($structInner, '</member>', $sliceStart);
            if (false === $closePos) {
                break;
            }
            $pos = $closePos + 9;
        }

        return $out;
    }

    private static function decodeBase64(string $payload): string
    {
        $decoded = base64_decode(trim($payload), true);

        return false === $decoded ? '' : $decoded;
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
