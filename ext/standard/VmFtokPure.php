<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Pure-PHP ftok() from stat() layout — glibc/musl algorithm (#12107, php-in-PHP).
 *
 * php-src: ext/standard/ftok.c — PHP_FUNCTION(ftok) delegates to libc ftok(3)
 */
final class VmFtokPure
{
    public static function available(): bool
    {
        return VmStatNative::available();
    }

    public static function invoke(string $pathname, int $projId): int
    {
        $st = VmStatNative::stat($pathname);
        if (false === $st) {
            return -1;
        }

        $dev = (int) ($st['dev'] ?? $st[0] ?? 0);
        $ino = (int) ($st['ino'] ?? $st[1] ?? 0);

        return (int) (
            ($ino & 0xFFFF)
            | (($dev & 0xFF) << 16)
            | (($projId & 0xFF) << 24)
        );
    }

    public static function lastErrorMessage(): string
    {
        return 'ftok() failed - No such file or directory';
    }
}
