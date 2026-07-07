<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * base64_decode() for compiled JIT/AOT modules (#17234, php-in-PHP).
 *
 * Runtime JIT path matches legacy LLVM: non-strict decode; invalid input → empty string.
 * Strict + compile-time literal folding stays in {@see base64_decode::call()}.
 *
 * SSOT: {@see VmString::base64_decode()}
 * php-src: ext/standard/base64.c — PHP_FUNCTION(base64_decode)
 */
final class Base64DecodeJitHelper
{
    public static function decodeArgv(string $data): string
    {
        $result = VmString::base64_decode($data, false);

        return false === $result ? '' : $result;
    }
}
