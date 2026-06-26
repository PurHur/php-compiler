<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * tempnam() path creation without libc mkstemp FFI (#12145, pairs {@see VmFsTempnamNative}).
 *
 * Exclusive-create loop via {@see VmFsOpenNative} mode `x`.
 *
 * php-src: ext/standard/file.c — php_open_temporary_file
 */
final class VmFsTempnamPure
{
    private const PATH_MAX = 4096;

    private const MAX_ATTEMPTS = 100;

    public static function mkstemp(string $dir, string $prefix): string|false
    {
        if (str_contains($dir, "\0") || str_contains($prefix, "\0")) {
            return false;
        }
        $sep = self::dirSeparator($dir);
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; ++$attempt) {
            $candidate = $dir.$sep.$prefix.self::randomSuffix();
            if (\strlen($candidate) >= self::PATH_MAX) {
                return false;
            }
            $handle = VmFsOpenNative::open($candidate, 'x');
            if (false === $handle) {
                continue;
            }
            VmFs::fclose($handle);

            return $candidate;
        }

        return false;
    }

    private static function randomSuffix(): string
    {
        try {
            return \bin2hex(VmString::randomBytes(6));
        } catch (\Throwable) {
            return \dechex(\random_int(0, 0xFFFFFF));
        }
    }

    private static function dirSeparator(string $dir): string
    {
        if ('' === $dir) {
            return '';
        }
        $last = $dir[\strlen($dir) - 1];

        return ('/' === $last || '\\' === $last) ? '' : '/';
    }
}
