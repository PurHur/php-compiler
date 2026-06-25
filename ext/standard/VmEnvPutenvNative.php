<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Pure PHP putenv/getenv process environ lookup — no libc FFI (#8086, #8992).
 *
 * Mutation lives in {@see VmEnv} local overlay; this class only resolves inherited names
 * from {@see VmEnvEnvironNative}.
 *
 * php-src: ext/standard/basic_functions.c — zif_putenv, zif_getenv
 */
final class VmEnvPutenvNative
{
    public static function available(): bool
    {
        return true;
    }

    public static function putenv(string $setting): bool
    {
        return true;
    }

    public static function getenv(string $name): string|false
    {
        $environ = VmEnvEnvironNative::enumerate();
        if (!\array_key_exists($name, $environ)) {
            return false;
        }

        return $environ[$name];
    }
}
