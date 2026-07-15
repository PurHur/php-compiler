<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlrpc;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/** xmlrpc_encode() table JIT/AOT helper (#19048). */
final class XmlrpcEncodeTableJitHelper
{
    public static function encodeListHashTable(HashTable $table): string
    {
        return self::wrapParam(self::encodeListInner($table));
    }

    public static function encodeStructHashTable(HashTable $table): string
    {
        return self::wrapParam(self::encodeStructInner($table));
    }

    private static function wrapParam(string $inner): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<param>'."\n"
            .'<value>'.$inner.'</value>'."\n"
            .'</param>';
    }

    private static function encodePayload(Variable $value): string
    {
        $type = $value->type;
        if (Variable::TYPE_NULL === $type) {
            return '<string></string>';
        }
        if (Variable::TYPE_BOOLEAN === $type) {
            return '<boolean>'.($value->toBool(null) ? '1' : '0').'</boolean>';
        }
        if (Variable::TYPE_INTEGER === $type) {
            return '<int>'.$value->toInt(null).'</int>';
        }
        if (Variable::TYPE_FLOAT === $type) {
            return '<double>'.self::formatDouble($value->toFloat(null)).'</double>';
        }
        if (Variable::TYPE_STRING === $type) {
            return '<string>'.self::escapeXml($value->toString(null)).'</string>';
        }
        if (Variable::TYPE_ARRAY === $type) {
            return self::encodeTable($value->toArray());
        }

        throw new \Exception('Cannot xmlrpc_encode() value of type '.$type);
    }

    private static function encodeTable(HashTable $table): string
    {
        if ($table->isPackedList()) {
            return self::encodeListInner($table);
        }

        return self::encodeStructInner($table);
    }

    private static function encodeListInner(HashTable $table): string
    {
        $out = '<array><data>';
        foreach ($table->exportKeyValuePairs(true) as [, $element]) {
            $out .= '<value>'.self::encodePayload($element->resolveIndirect()).'</value>';
        }

        return $out.'</data></array>';
    }

    private static function encodeStructInner(HashTable $table): string
    {
        $out = '<struct>';
        foreach ($table->exportKeyValuePairs(true) as [$key, $element]) {
            $name = self::arrayKeyToString($key->resolveIndirect());
            $out .= '<member><name>'.self::escapeXml($name).'</name><value>'
                .self::encodePayload($element->resolveIndirect()).'</value></member>';
        }

        return $out.'</struct>';
    }

    private static function arrayKeyToString(Variable $key): string
    {
        $type = $key->type;
        if (Variable::TYPE_STRING === $type) {
            return $key->toString(null);
        }
        if (Variable::TYPE_INTEGER === $type) {
            return (string) $key->toInt(null);
        }

        return $key->toString(null);
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
        if (is_infinite($value)) {
            return $value > 0.0 ? 'INF' : '-INF';
        }

        return rtrim(rtrim(sprintf('%.17F', $value), '0'), '.');
    }
}
