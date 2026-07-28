<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM file reads without libc open/read FFI (#8920, pairs {@see VmFsReadNative}).
 *
 * Bootstrap path when FFI is disabled: host fopen/fread under Zend VM, or compiled
 * stream I/O once {@see VmFsOpenNative} pure open lands (#8950).
 *
 * php-src: ext/standard/file.c — php_stream_copy_to_mem
 */
final class VmFsReadPure
{
    private const CHUNK = 8192;

    public static function available(): bool
    {
        return \function_exists('fopen');
    }

    public static function read(string $path): string|false
    {
        return self::readSlice($path, 0, null);
    }

    /**
     * Read a byte slice from a regular file (php-src ext/standard/file.c offset/length).
     *
     * Reads via the open stream until EOF (or $length) — never caps with filesize().
     * PHP's stat cache stays stale across content writes until clearstatcache, but
     * file_get_contents must still return fresh bytes (php-src php_stream_copy_to_mem;
     * #24339 FILE_APPEND mid-read).
     *
     * @return string|false false on open/read failure; '' when offset is past EOF
     */
    public static function readSlice(string $path, int $offset = 0, ?int $length = null): string|false
    {
        if (str_contains($path, "\0")) {
            return false;
        }
        $path = VmFsLocalPath::resolveAgainstCwd($path);
        // is_readable() is false in user-script AOT while fopen succeeds (#18897, re-#18695).
        $fp = @\fopen($path, 'rb');
        if (false === $fp) {
            return false;
        }

        if ($offset < 0) {
            if (0 !== @\fseek($fp, 0, \SEEK_END)) {
                @\fclose($fp);

                return false;
            }
            $fileSize = @\ftell($fp);
            if (false === $fileSize) {
                @\fclose($fp);

                return false;
            }
            $offset = $fileSize + $offset;
            if ($offset < 0) {
                $offset = 0;
            }
        }

        if (0 !== $offset && 0 !== @\fseek($fp, $offset, \SEEK_SET)) {
            @\fclose($fp);

            return false;
        }

        if (null !== $length && $length < 0) {
            @\fclose($fp);

            return '';
        }

        $content = self::readStreaming($fp, $length);
        @\fclose($fp);

        return $content;
    }

    /**
     * @param resource $fp
     *
     * @return string|false
     */
    private static function readStreaming($fp, ?int $maxBytes = null): string|false
    {
        $parts = [];
        $remaining = $maxBytes;
        while (true) {
            $chunk = null === $remaining ? self::CHUNK : min(self::CHUNK, $remaining);
            if ($chunk <= 0) {
                break;
            }
            $data = @\fread($fp, $chunk);
            if (false === $data) {
                return false;
            }
            if ('' === $data) {
                break;
            }
            $parts[] = $data;
            if (null !== $remaining) {
                $remaining -= \strlen($data);
                if ($remaining <= 0) {
                    break;
                }
            }
        }

        return '' === $parts ? '' : implode('', $parts);
    }
}
