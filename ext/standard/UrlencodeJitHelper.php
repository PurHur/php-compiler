<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * urlencode()/rawurlencode() for compiled JIT/AOT modules (#14724, php-in-PHP).
 *
 * SSOT: {@see VmString::urlencode()} / {@see VmString::rawurlencode()}
 * php-src: ext/standard/url.c — PHP_FUNCTION(urlencode), PHP_FUNCTION(rawurlencode)
 */
final class UrlencodeJitHelper
{
    public static function urlencodeArgv(string $data): string
    {
        return VmString::urlencode($data);
    }

    public static function rawurlencodeArgv(string $data): string
    {
        return VmString::rawurlencode($data);
    }
}
