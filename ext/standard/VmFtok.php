<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * System V IPC key via stat()-derived ftok layout (#6296, #12107).
 *
 * Mirrors {@see FtokJitHelper} — no libc ftok(3) FFI.
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(ftok)
 */
final class VmFtok
{
    public static function available(): bool
    {
        return VmFtokPure::available();
    }

    public static function invoke(string $pathname, int $projId): int
    {
        return VmFtokPure::invoke($pathname, $projId);
    }

    public static function lastErrorMessage(): string
    {
        return VmFtokPure::lastErrorMessage();
    }
}
