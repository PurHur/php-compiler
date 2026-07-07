<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * base64_encode()/base64_decode() for compiled JIT/AOT modules (#17234, #17249).
 *
 * Single compile unit for nested JIT linking of both ABI bridges.
 * SSOT: {@see VmString::base64_encode()} / {@see VmString::base64_decode()}
 * php-src: ext/standard/base64.c
 */
final class Base64JitHelper
{
    public static function encodeArgv(string $data): string
    {
        return VmString::base64_encode($data);
    }

    public static function decodeArgv(string $data): string
    {
        $result = VmString::base64_decode($data, false);

        return false === $result ? '' : $result;
    }
}
