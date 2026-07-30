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
        // NestedJIT cannot compile full VmFs today (ExternalMethod null → handle 0). For
        // php://memory|temp allocate a live handle in the shared JitOpenStreamHandles table so
        // is_resource / ++/-- TypeError work under AOT (#23777). Other paths still use VmFs.
        if (JitOpenStreamHandles::isMemoryUri($path)) {
            if (!JitOpenStreamHandles::modeLooksValid($mode)) {
                return -1;
            }

            return JitOpenStreamHandles::alloc();
        }

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

    /** @return int ABI for __compiler_fseek (0 success, -1 failure) — memory streams under AOT (#25299). */
    public static function fseekArgv(int $handle, int $offset, int $whence): int
    {
        if (JitMemoryStreamHelper::isOpen($handle)) {
            return JitMemoryStreamHelper::seek($handle, $offset, $whence);
        }

        return VmFs::fseek($handle, $offset, $whence);
    }

    /** @return int ABI for __compiler_ftell (-1 on failure) — memory streams under AOT (#25299). */
    public static function ftellArgv(int $handle): int
    {
        if (JitMemoryStreamHelper::isOpen($handle)) {
            return JitMemoryStreamHelper::tellArgv($handle);
        }
        $result = VmFs::ftell($handle);
        if (false === $result) {
            return -1;
        }

        return (int) $result;
    }

    /** @return 0|1 ABI for __compiler_stream_supports (issue #19462 — same VmFs table as fopen/tmpfile) */
    public static function supportsArgv(int $handle, int $feature): int
    {
        return VmFs::streamSupports($handle, $feature) ? 1 : 0;
    }
}
