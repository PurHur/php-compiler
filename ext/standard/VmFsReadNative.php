<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * libc open(2)/read(2) for VM file reads; falls back to {@see VmFsReadPure} when FFI unavailable (#8920).
 *
 * php-src: ext/standard/file.c — php_stream_copy_to_mem
 * JIT/AOT: __compiler_file_get_contents LLVM lowering (unchanged).
 */
final class VmFsReadNative
{
    private const O_RDONLY = 0;

    private const SEEK_SET = 0;

    private const SEEK_END = 2;

    private const CHUNK = 8192;

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi() || VmFsReadPure::available();
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
        $ffi = self::ffi();
        if (null === $ffi) {
            return VmFsReadPure::readSlice($path, $offset, $length);
        }

        try {
            $fd = (int) $ffi->open($path, self::O_RDONLY);
            if ($fd < 0) {
                return false;
            }

            $fileSize = (int) $ffi->lseek($fd, 0, self::SEEK_END);
            if ($fileSize < 0) {
                // /proc, pipes, devices — no size via lseek; stream from open position (#8744).
                if (0 !== $offset) {
                    $ffi->close($fd);

                    return false;
                }
                $ffi->lseek($fd, 0, self::SEEK_SET);
                $content = self::readStreaming($ffi, $fd, $length);
                $ffi->close($fd);

                return $content;
            }
            if ($offset < 0) {
                $offset = $fileSize + $offset;
                if ($offset < 0) {
                    $offset = 0;
                }
            }
            if ($offset > $fileSize) {
                $ffi->close($fd);

                return '';
            }
            $seekPos = (int) $ffi->lseek($fd, $offset, self::SEEK_SET);
            if ($seekPos < 0) {
                $ffi->close($fd);

                return false;
            }

            $toRead = null === $length ? $fileSize - $offset : $length;
            if ($toRead < 0) {
                $ffi->close($fd);

                return '';
            }
            if ($offset + $toRead > $fileSize) {
                $toRead = $fileSize - $offset;
            }
            if (0 === $toRead) {
                // /proc and other pseudo-files report size 0 via lseek/filesize but have content (#11744).
                if (0 === $offset) {
                    $ffi->lseek($fd, 0, self::SEEK_SET);
                    $content = self::readStreaming($ffi, $fd, $length);
                    $ffi->close($fd);

                    return $content;
                }
                $ffi->close($fd);

                return '';
            }

            $parts = [];
            $remaining = $toRead;
            $buf = $ffi->new('char['.self::CHUNK.']');
            while ($remaining > 0) {
                $chunk = min(self::CHUNK, $remaining);
                $n = (int) $ffi->read($fd, \FFI::addr($buf[0]), $chunk);
                if ($n < 0) {
                    $ffi->close($fd);

                    return false;
                }
                if (0 === $n) {
                    break;
                }
                $parts[] = \FFI::string($buf, $n);
                $remaining -= $n;
            }
            $ffi->close($fd);

            return '' === $parts ? '' : implode('', $parts);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Read until EOF (or $maxBytes) for non-seekable fds opened at offset 0.
     *
     * @return string|false
     */
    private static function readStreaming(\FFI $ffi, int $fd, ?int $maxBytes = null): string|false
    {
        $parts = [];
        $remaining = $maxBytes;
        $buf = $ffi->new('char['.self::CHUNK.']');
        while (true) {
            $chunk = null === $remaining ? self::CHUNK : min(self::CHUNK, $remaining);
            if ($chunk <= 0) {
                break;
            }
            $n = (int) $ffi->read($fd, \FFI::addr($buf[0]), $chunk);
            if ($n < 0) {
                return false;
            }
            if (0 === $n) {
                break;
            }
            $parts[] = \FFI::string($buf, $n);
            if (null !== $remaining) {
                $remaining -= $n;
                if ($remaining <= 0) {
                    break;
                }
            }
        }

        return '' === $parts ? '' : implode('', $parts);
    }

    private static function ffiEnabled(): bool
    {
        $v = getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== strtolower($v)) {
            return false;
        }

        return true;
    }

    private static function ffi(): ?\FFI
    {
        if (!self::ffiEnabled()) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (self::$ffiUnavailable || !\extension_loaded('ffi')) {
            self::$ffiUnavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
typedef long ssize_t;
typedef unsigned long size_t;
typedef long off_t;
int open(const char *pathname, int flags, ...);
ssize_t read(int fd, void *buf, size_t count);
off_t lseek(int fd, off_t offset, int whence);
int close(int fd);
CDEF;

        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        self::$ffiUnavailable = true;

        return null;
    }
}
