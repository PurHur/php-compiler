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
            default:
                throw new \Exception('Cannot xmlrpc_encode() value of type '.$value->type);
        }
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
