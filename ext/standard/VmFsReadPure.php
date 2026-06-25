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
     * @return string|false false on open/read failure; '' when offset is past EOF
     */
    public static function readSlice(string $path, int $offset = 0, ?int $length = null): string|false
    {
        if (str_contains($path, "\0")) {
            return false;
        }
        if (!\is_readable($path)) {
            return false;
        }

        $fp = @\fopen($path, 'rb');
        if (false === $fp) {
            return false;
        }

        $fileSize = @\filesize($path);
        if (false === $fileSize) {
            if (0 !== $offset) {
                @\fclose($fp);

                return false;
            }
            $content = self::readStreaming($fp, $length);
            @\fclose($fp);

            return $content;
        }

        if ($offset < 0) {
            $offset = $fileSize + $offset;
            if ($offset < 0) {
                $offset = 0;
            }
        }
        if ($offset > $fileSize) {
            @\fclose($fp);

            return '';
        }

        if (0 !== $offset && 0 !== @\fseek($fp, $offset, \SEEK_SET)) {
            @\fclose($fp);

            return false;
        }

        $toRead = null === $length ? $fileSize - $offset : $length;
        if ($toRead < 0) {
            @\fclose($fp);

            return '';
        }
        if ($offset + $toRead > $fileSize) {
            $toRead = $fileSize - $offset;
        }
        if (0 === $toRead) {
            // /proc pseudo-files report size 0 but stream content (e.g. /proc/self/environ, #11744).
            if (0 === $offset) {
                $content = self::readStreaming($fp, $length);
                @\fclose($fp);

                return $content;
            }
            @\fclose($fp);

            return '';
        }

        $parts = [];
        $remaining = $toRead;
        while ($remaining > 0) {
            $chunk = min(self::CHUNK, $remaining);
            $data = @\fread($fp, $chunk);
            if (false === $data || '' === $data) {
                @\fclose($fp);

                return false === $data ? false : ('' === $parts ? '' : implode('', $parts));
            }
            $parts[] = $data;
            $remaining -= \strlen($data);
        }
        @\fclose($fp);

        return '' === $parts ? '' : implode('', $parts);
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
