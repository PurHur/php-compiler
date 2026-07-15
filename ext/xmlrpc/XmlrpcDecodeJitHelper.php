<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlrpc;

/**
 * xmlrpc_decode() JIT step-1 — XML string to JSON text (#19048).
 *
 * Manual JSON emitter avoids nested json_encode() JIT during helper compile.
 */
final class XmlrpcDecodeJitHelper
{
    public static function decodeToJson(string $xml): ?string
    {
        $decoded = VmXmlrpc::decode($xml);
        if (false === $decoded) {
            return null;
        }

        return self::emitJson($decoded);
    }

    /**
     * @param mixed $value
     */
    private static function emitJson($value): string
    {
        if (null === $value) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            return self::quoteJsonString($value);
        }
        if (!is_array($value)) {
            return 'null';
        }
        if ([] === $value) {
            return '[]';
        }
        if (array_is_list($value)) {
            $parts = [];
            foreach ($value as $item) {
                $parts[] = self::emitJson($item);
            }

            return '['.implode(',', $parts).']';
        }
        $parts = [];
        foreach ($value as $key => $item) {
            $parts[] = self::quoteJsonString((string) $key).':'.self::emitJson($item);
        }

        return '{'.implode(',', $parts).'}';
    }

    private static function quoteJsonString(string $value): string
    {
        $escaped = str_replace(
            ['\\', '"', "\n", "\r", "\t"],
            ['\\\\', '\\"', '\\n', '\\r', '\\t'],
            $value
        );

        return '"'.$escaped.'"';
    }
}
