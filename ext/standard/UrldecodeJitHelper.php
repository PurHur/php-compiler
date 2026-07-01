<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * urldecode()/rawurldecode() for compiled JIT/AOT modules (#14726, php-in-PHP).
 *
 * SSOT: {@see VmString::urldecode()} / {@see VmString::rawurldecode()}
 * php-src: ext/standard/url.c — PHP_FUNCTION(urldecode), PHP_FUNCTION(rawurldecode)
 */
final class UrldecodeJitHelper
{
    public static function urldecodeArgv(string $data): string
    {
        return VmString::urldecode($data);
    }

    public static function rawurldecodeArgv(string $data): string
    {
        return VmString::rawurldecode($data);
    }
}
