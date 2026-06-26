<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * gethostname() without libc gethostname(2) FFI — /proc, env, /etc (#12169, pairs {@see VmHost}).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(gethostname)
 */
final class VmHostPure
{
    public static function available(): bool
    {
        return '' !== self::readHostname();
    }

    /** @return string|false */
    public static function gethostname()
    {
        $host = self::readHostname();

        return '' === $host ? false : $host;
    }

    private static function readHostname(): string
    {
        if ('Linux' === \PHP_OS_FAMILY) {
            $fromProc = self::readTrimmed('/proc/sys/kernel/hostname');
            if (null !== $fromProc) {
                return $fromProc;
            }
        }

        $env = \getenv('HOSTNAME');
        if (false !== $env && '' !== $env) {
            return $env;
        }

        $fromEtc = self::readTrimmed('/etc/hostname');
        if (null !== $fromEtc) {
            return $fromEtc;
        }

        return '';
    }

    private static function readTrimmed(string $path): ?string
    {
        $raw = self::readText($path);
        if (null === $raw) {
            return null;
        }
        $trimmed = \trim($raw);

        return '' === $trimmed ? null : $trimmed;
    }

    private static function readText(string $path): ?string
    {
        if (\str_contains($path, "\0") || !\is_readable($path)) {
            return null;
        }

        $viaVmFs = VmFsReadNative::read($path);
        if (false !== $viaVmFs && '' !== $viaVmFs) {
            return $viaVmFs;
        }

        $content = @\file_get_contents($path);
        if (false === $content) {
            return null;
        }

        return $content;
    }
}
