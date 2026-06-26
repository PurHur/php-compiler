<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM proc_nice() — pure PHP default ({@see VmProcNicePure}, #7862, #12183).
 *
 * No libc nice(3) FFI on the default path — shrinks native link surface for self-host/M5.
 *
 * Mirrors {@see JitProcNice} — no Zend host proc_nice() delegation on VM.
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(proc_nice)
 */
final class VmProcNiceNative
{
    public static function available(): bool
    {
        return VmProcNicePure::available();
    }

    public static function proc_nice(int $priority): bool
    {
        return VmProcNicePure::proc_nice($priority);
    }
}
