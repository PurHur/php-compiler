<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * convert_uuencode()/convert_uudecode() for compiled JIT/AOT modules (#13227, #18827, #26898, #30811).
 *
 * Thin argv bridge — algorithm in {@see VmConvertUu}, NestedJIT-bundled with this file
 * (peer {@see SoundexJitHelper} / #30790). Solo NestedJIT of the former self-contained
 * helper SIGSEGV'd under thin AOT (`$s[$i]` / 256-arm match tables).
 *
 * php-src: ext/standard/uuencode.c
 */
final class ConvertUuJitHelper
{
    private const MSG_INVALID = 'convert_uudecode(): Argument #1 ($data) is not a valid uuencoded string';

    public static function encode(string $data): string
    {
        return VmConvertUu::encode($data);
    }

    /**
     * @return string|false
     */
    public static function decodeArgv(string $data)
    {
        $result = VmConvertUu::decode($data);
        if (false === $result) {
            TriggerErrorJitHelper::warning(self::MSG_INVALID);

            return false;
        }

        return $result;
    }
}
