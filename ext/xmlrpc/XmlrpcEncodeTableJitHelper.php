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
                return self::encodeTable($value->toArray());
            default:
                throw new \Exception('Cannot xmlrpc_encode() value of type '.$value->type);
        }
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
        if (Variable::TYPE_STRING === $key->type) {
            return $key->toString(null);
        }
        if (Variable::TYPE_INTEGER === $key->type) {
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
