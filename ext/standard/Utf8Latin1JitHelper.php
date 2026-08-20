<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * utf8_encode()/utf8_decode() for compiled JIT/AOT modules (#9912, #22701, #32879).
 *
 * Thin argv bridge — algorithm in {@see VmUtf8Latin1}, NestedJIT-bundled with this file
 * (peer {@see ConvertUuJitHelper} / #30811). `decodeArgv` naming avoids stale helper-runtime
 * `::decode` symbols.
 *
 * E_DEPRECATED is emitted from utf8_encode/decode::call via {@see Utf8EndecDeprecation::emitJit}.
 *
 * php-src: ext/standard/utf8.c — php_utf8_encode, php_utf8_decode
 */
final class Utf8Latin1JitHelper
{
    public static function encode(string $data): string
    {
        return VmUtf8Latin1::encode($data);
    }

    public static function decodeArgv(string $data): string
    {
        return VmUtf8Latin1::decode($data);
    }
}
