<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * proc_nice() without libc nice(3) FFI — Linux /proc/self/autogroup (#12183).
 *
 * Uses direct procfs I/O (not {@see VmFsWriteNative} O_TRUNC open) so repeated
 * proc_nice() calls remain valid after FFI-disabled bootstrap paths.
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(proc_nice)
 * Linux procfs: man 7 sched — writing nice value to /proc/[pid]/autogroup
 */
final class VmProcNicePure
{
    private const AUTOGROUP = '/proc/self/autogroup';

    public static function available(): bool
    {
        return 'Linux' === \PHP_OS_FAMILY && \is_readable(self::AUTOGROUP) && \is_writable(self::AUTOGROUP);
    }

    public static function proc_nice(int $priority): bool
    {
        if (!self::available()) {
            return false;
        }

        $current = self::readAutogroupNice();
        if (null === $current) {
            return false;
        }

        $target = $current + $priority;
        if ($target === $current) {
            return true;
        }

        return self::writeAutogroupNice($target);
    }

    private static function readAutogroupNice(): ?int
    {
        $raw = self::readAutogroup();
        if (null === $raw) {
            return null;
        }
        if (!preg_match('/\bnice\s+(-?\d+)\b/', $raw, $m)) {
            return null;
        }

        return (int) $m[1];
    }

    private static function readAutogroup(): ?string
    {
        if (!\is_readable(self::AUTOGROUP)) {
            return null;
        }

        $raw = @\file_get_contents(self::AUTOGROUP);
        if (!\is_string($raw) || '' === $raw) {
            return null;
        }

        return \trim($raw);
    }

    private static function writeAutogroupNice(int $nice): bool
    {
        if (!\is_writable(self::AUTOGROUP)) {
            return false;
        }

        return false !== @\file_put_contents(self::AUTOGROUP, (string) $nice);
    }
}
