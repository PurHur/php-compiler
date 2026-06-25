<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * flock/fgets/fseek/stream_get_contents for compiled JIT/AOT embed modules (#9393).
 *
 * SSOT: {@see VmFs}
 * php-src: ext/standard/flock.c, ext/standard/streams.c, ext/standard/file.c
 */
final class StreamReadJitHelper
{
    /** @return 0|1 ABI for __compiler_flock */
    public static function flockArgv(int $handle, int $operation): int
    {
        return VmFs::flock($handle, $operation) ? 1 : 0;
    }

    /** @return int ABI for __compiler_fpassthru (-1 on failure) */
    public static function fpassthruArgv(int $handle): int
    {
        $result = VmFs::fpassthru($handle);
        if (false === $result) {
            return -1;
        }

        return (int) $result;
    }

    /** @return 0|1 ABI for __compiler_ftruncate */
    public static function ftruncateArgv(int $handle, int $size): int
    {
        return VmFs::ftruncate($handle, $size) ? 1 : 0;
    }

    /** @return int ABI for __compiler_ftell (-1 on failure) */
    public static function ftellArgv(int $handle): int
    {
        $result = VmFs::ftell($handle);
        if (false === $result) {
            return -1;
        }

        return (int) $result;
    }

    public static function fgetcArgv(int $handle): ?string
    {
        $result = VmFs::fgetc($handle);
        if (false === $result) {
            return null;
        }

        return (string) $result;
    }

    public static function fgetsArgv(int $handle, int $length): ?string
    {
        if ($length <= 0) {
            return null;
        }
        $result = VmFs::fgets($handle, $length);
        if (false === $result) {
            return null;
        }

        return (string) $result;
    }

    public static function streamGetLineArgv(int $handle, int $maxLength, ?string $ending): ?string
    {
        $result = VmFs::streamGetLine($handle, $maxLength, $ending);
        if (false === $result) {
            return null;
        }

        return (string) $result;
    }

    /** @return int ABI for __compiler_fseek (0 success, -1 failure) */
    public static function fseekArgv(int $handle, int $offset, int $whence): int
    {
        return VmFs::fseek($handle, $offset, $whence);
    }

    public static function streamGetContentsArgv(int $handle, int $maxlength, int $offset): ?string
    {
        if ($offset < -1) {
            return null;
        }
        $result = VmFs::streamGetContents($handle, $maxlength, $offset);
        if (false === $result) {
            return null;
        }

        return (string) $result;
    }

    /** @return int ABI for __compiler_stream_copy_to_stream (-1 on failure) */
    public static function streamCopyToStreamArgv(int $source, int $dest, int $maxlength, int $offset): int
    {
        $result = VmFs::streamCopyToStream($source, $dest, $maxlength, $offset);
        if (false === $result) {
            return -1;
        }

        return (int) $result;
    }

    public static function streamCopyToStringArgv(int $handle, int $maxlength, int $offset): ?string
    {
        $result = VmFs::streamCopyToString($handle, $maxlength, $offset);
        if (false === $result) {
            return null;
        }

        return (string) $result;
    }
}
