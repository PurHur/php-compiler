<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * base64_encode() for compiled JIT/AOT modules (#17234, php-in-PHP).
 *
 * SSOT: {@see VmString::base64_encode()}
 * php-src: ext/standard/base64.c — PHP_FUNCTION(base64_encode)
 */
final class Base64EncodeJitHelper
{
    public static function encodeArgv(string $data): string
    {
        return VmString::base64_encode($data);
    }
}
