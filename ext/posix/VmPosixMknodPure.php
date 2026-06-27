<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

/**
 * posix_mknod()/posix_mkfifo() via thin libc ABI (#12733).
 *
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_mknod), posix_mkfifo
 */
final class VmPosixMknodPure
{
    public static function available(): bool
    {
        return PosixLibcThinAbi::available();
    }

    public static function mknod(string $path, int $mode, int $major = 0, int $minor = 0): ?bool
    {
        if (!self::available()) {
            return null;
        }

        $dev = self::makeDev($mode, $major, $minor);

        return 0 === PosixLibcThinAbi::mknod($path, $mode, $dev);
    }

    public static function mkfifo(string $path, int $mode): ?bool
    {
        if (!self::available()) {
            return null;
        }

        $fifoMode = $mode | PosixConstants::S_IFIFO;

        return 0 === PosixLibcThinAbi::mkfifo($path, $fifoMode);
    }

    public static function lastErrno(): int
    {
        return PosixLibcThinAbi::readErrno();
    }

    private static function makeDev(int $mode, int $major, int $minor): int
    {
        $type = $mode & PosixConstants::S_IFMT;
        if ($type !== PosixConstants::S_IFCHR && $type !== PosixConstants::S_IFBLK) {
            return 0;
        }

        return (($major & 0x00000fff) << 8)
            | ($minor & 0x000000ff)
            | (($major & 0xfffff000) << 32)
            | (($minor & 0xffffff00) << 12);
    }
}
