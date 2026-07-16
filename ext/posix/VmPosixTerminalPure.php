<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

/**
 * posix_getlogin()/posix_ttyname()/posix_isatty() — PHP-in-PHP (#6504).
 *
 * ttyname/isatty: Linux /proc/self/fd readlink SSOT (no libc ttyname/isatty FFI).
 * getlogin: thin libc getlogin(3) via {@see PosixLibcThinAbi} (utmp; no pure-PHP substitute).
 *
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_getlogin), posix_ttyname, posix_isatty
 */
final class VmPosixTerminalPure
{
    public static function getlogin(): ?string
    {
        if (!PosixLibcThinAbi::available()) {
            return null;
        }

        $name = PosixLibcThinAbi::getlogin();
        if (null === $name || '' === $name) {
            return null;
        }

        return $name;
    }

    public static function lastErrno(): int
    {
        return PosixLibcThinAbi::readErrno();
    }

    /**
     * @return string|null tty device path, or null when fd is not a terminal
     */
    public static function ttyname(int $fd): ?string
    {
        if ($fd < 0) {
            return null;
        }

        $path = self::fdPath($fd);
        if (null === $path || !self::isTtyPath($path)) {
            return null;
        }

        return $path;
    }

    public static function isatty(int $fd): bool
    {
        if ($fd < 0) {
            return false;
        }

        $path = self::fdPath($fd);

        return null !== $path && self::isTtyPath($path);
    }

    private static function fdPath(int $fd): ?string
    {
        if ('Linux' !== \PHP_OS_FAMILY) {
            return null;
        }

        $link = '/proc/self/fd/'.$fd;
        if (!\is_link($link) && !\file_exists($link)) {
            return null;
        }

        $target = @\readlink($link);
        if (!\is_string($target) || '' === $target) {
            return null;
        }

        return $target;
    }

    private static function isTtyPath(string $path): bool
    {
        if ('/dev/tty' === $path || '/dev/console' === $path) {
            return true;
        }

        return 1 === \preg_match('#^/dev/(pts/\\d+|tty\\d*|vc/\\d+)$#', $path);
    }
}
