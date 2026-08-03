<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * strstr()/stristr() algorithm peer for unit tests (#14778, #27185).
 *
 * AOT/JIT call sites use {@see \PHPCompiler\JIT\Builtin\StringStrstr}
 * (`phpc_strstr_scan` / `phpc_stristr_scan`) — NestedJIT helpers mis-materialize
 * nullable {@see __string__*} under thin AOT. VM SSOT remains {@see VmString::strstr()} /
 * {@see VmString::stristr()}.
 * php-src: ext/standard/string.c — PHP_FUNCTION(strstr), PHP_FUNCTION(stristr)
 */
final class StrstrJitHelper
{
    /**
     * @return ?string null when strstr() would return false (JIT null __string__*)
     */
    public static function strstrArgv(string $haystack, string $needle, int $beforeNeedle): ?string
    {
        $result = VmString::strstr($haystack, $needle, 0 !== $beforeNeedle);

        return false === $result ? null : $result;
    }

    /**
     * @return ?string null when stristr() would return false (JIT null __string__*)
     */
    public static function stristrArgv(string $haystack, string $needle, int $beforeNeedle): ?string
    {
        $result = VmString::stristr($haystack, $needle, 0 !== $beforeNeedle);

        return false === $result ? null : $result;
    }
}
