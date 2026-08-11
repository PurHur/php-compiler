<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Process environment enumeration without libc environ FFI (#5075, #5079, #8940).
 *
 * Linux: /proc/self/environ via {@see VmFsReadNative}. Non-Linux: empty (no FFI fallback).
 *
 * php-src: ext/standard/basic_functions.c — zif_getenv argc==0 via environ walk.
 */
final class VmEnvEnvironNative
{
    /**
     * @return array<string, string>
     */
    public static function enumerate(): array
    {
        if ('Linux' === \PHP_OS_FAMILY) {
            return self::enumerateLinuxProcEnviron();
        }

        return [];
    }

    /** Init-safe native hashtable mirror for JIT/AOT superglobal refresh (#19157, #21580, #30225). */
    public static function mirrorIntoNativeHashtable(int $destPtr): void
    {
        if ($destPtr <= 0) {
            return;
        }
        foreach (self::enumerate() as $name => $value) {
            phpc_native_ht_set_string_key($destPtr, $name, $value);
        }
    }

    /**
     * Linux bootstrap: /proc/self/environ via VmFsReadNative (#5079, #8426).
     *
     * @return array<string, string>
     */
    private static function enumerateLinuxProcEnviron(): array
    {
        // is_readable() is false in user-script AOT while fopen succeeds (#18897).
        $raw = VmFsReadNative::read('/proc/self/environ');
        if (false === $raw || '' === $raw) {
            return [];
        }

        $result = [];
        foreach (explode("\0", $raw) as $pair) {
            if ('' === $pair) {
                continue;
            }
            $eq = strpos($pair, '=');
            if (false === $eq) {
                continue;
            }
            $result[substr($pair, 0, $eq)] = substr($pair, $eq + 1);
        }

        return $result;
    }
}
