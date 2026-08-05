<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * strcoll() helper retained for VM/unit SSOT (#13566). Thin AOT/JIT uses
 * {@see \PHPCompiler\JIT\Builtin\StringStrcoll} libc trampoline (#27059) —
 * NestedJIT mis-reads {@see __string__*} under AOT (silent 0).
 *
 * SSOT: {@see VmLocaleCollate::strcoll()}
 * php-src: ext/standard/string.c — PHP_FUNCTION(strcoll)
 */
final class StrcollJitHelper
{
    public static function strcollArgv(string $a, string $b): int
    {
        return VmLocaleCollate::strcoll($a, $b);
    }
}
