<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlrpc;

use PHPCompiler\VM\Variable;

/** xmlrpc_encode() scalar JIT/AOT helper (#19048). */
final class XmlrpcEncodeScalarJitHelper
{
    public static function encodeValue(Variable $value): string
    {
        return self::wrapParam(self::encodeScalarPayload($value->resolveIndirect()));
    }

    private static function wrapParam(string $inner): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<param>'."\n"
            .'<value>'.$inner.'</value>'."\n"
            .'</param>';
    }

    private static function encodeScalarPayload(Variable $value): string
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

        throw new \Exception('Cannot xmlrpc_encode() value of type '.$type);
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
