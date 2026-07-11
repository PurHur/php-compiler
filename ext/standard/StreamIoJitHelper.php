<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * fopen/fread/fwrite/tmpfile/popen for compiled JIT/AOT embed modules (#10326).
 *
 * SSOT: {@see VmFs}
 * php-src: ext/standard/file.c, ext/standard/streamsfuncs.c
 */
final class StreamIoJitHelper
{
    /** @return int ABI for __compiler_fopen (-1 on failure) */
    public static function fopenArgv(string $path, string $mode): int
    {
        $handle = VmFs::fopen($path, $mode);
        if (false === $handle) {
            return -1;
        }

        return (int) $handle;
    }

    /** @return int ABI for __compiler_popen (-1 on failure) */
    public static function popenArgv(string $command, string $mode): int
    {
        $handle = VmFs::popen($command, $mode);
        if (false === $handle) {
            return -1;
        }

        return (int) $handle;
    }

    /** @return int ABI for __compiler_tmpfile (-1 on failure) */
    public static function tmpfileArgv(): int
    {
        $handle = VmFs::tmpfile();
        if (false === $handle) {
            return -1;
        }

        return (int) $handle;
    }

    public static function freadArgv(int $handle, int $length): ?string
    {
        if ($length < 0) {
            return null;
        }
        if (0 === $length) {
            return '';
        }
        try {
            $data = VmFs::fread($handle, $length);
        } catch (\ValueError) {
            return null;
        }
        if (false === $data) {
            return null;
        }

        return (string) $data;
    }

    /** @return int ABI for __compiler_fwrite (-1 on failure) */
    public static function fwriteArgv(int $handle, string $data, int $length): int
    {
        $written = VmFs::fwrite($handle, $data, $length < 0 ? null : $length);
        if (false === $written) {
            return -1;
        }

        return (int) $written;
    }
}
