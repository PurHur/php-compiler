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

    /**
     * Linux bootstrap: /proc/self/environ via VmFsReadNative (#5079, #8426).
     *
     * @return array<string, string>
     */
    private static function enumerateLinuxProcEnviron(): array
    {
        if (!\is_readable('/proc/self/environ')) {
            return [];
        }

        $raw = @\file_get_contents('/proc/self/environ');
        if (false === $raw || '' === $raw) {
            $raw = VmFsReadNative::read('/proc/self/environ');
        }
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
