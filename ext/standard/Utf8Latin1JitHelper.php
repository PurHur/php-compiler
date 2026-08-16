<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * utf8_encode()/utf8_decode() for compiled JIT/AOT modules (#9912, php-in-PHP).
 *
 * SSOT: {@see VmString::utf8_encode()} / {@see VmString::utf8_decode()}
 * php-src: ext/standard/utf8.c — php_utf8_encode, php_utf8_decode
 *
 * E_DEPRECATED is emitted from utf8_encode/decode::call via {@see Utf8EndecDeprecation::emitJit}
 * (caller module; #31176) — not here — so AOT user binaries record error_get_last.
 */
final class Utf8Latin1JitHelper
{
    public static function encode(string $data): string
    {
        return VmString::utf8_encode($data);
    }

    public static function decode(string $data): string
    {
        return VmString::utf8_decode($data);
    }
}
