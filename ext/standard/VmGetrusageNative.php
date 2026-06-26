<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM getrusage() — pure PHP /proc/self/stat, no libc getrusage(2) FFI (#8970, #5388).
 *
 * Mirrors {@see GetrusageJitHelper} — {@see VmGetrusagePure} SSOT.
 *
 * php-src: ext/standard/microtime.c — PHP_FUNCTION(getrusage)
 */
final class VmGetrusageNative
{
    public static function available(): bool
    {
        return VmGetrusagePure::available();
    }

    /** php-src: if (pwho == 1) who = RUSAGE_CHILDREN; */
    public static function normalizeWho(int $who): int
    {
        if (1 === $who) {
            return -1;
        }

        return $who;
    }

    /**
     * @return array<string, int>|false
     */
    public static function getrusage(int $who = 0): array|false
    {
        return VmGetrusagePure::getrusage($who);
    }
}
