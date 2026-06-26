<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * gzopen/gzwrite/gzread/gzclose — zlib stream resource API (ext/zlib/zlib.c, #6168).
 *
 * VM via {@see VmGzStreamPure} buffered I/O + {@see VmZlib} — no libz gzFile FFI (#8936, #8220).
 * JIT/AOT via {@see \PHPCompiler\JIT\Builtin\GzStreamIoJit} + {@see JitGzfile} / {@see JitReadgzfile} (#4657 phase 2).
 */
final class VmGzStream
{
    public static function gzopen(string $filename, string $mode, int $useIncludePath = 0): int|false
    {
        if (!VmGzStreamNative::available()) {
            return false;
        }
        if (0 !== ($useIncludePath & 1)) {
            $resolved = VmFs::resolveIncludePath($filename);
            if (false !== $resolved) {
                $filename = $resolved;
            }
        }

        return VmGzStreamNative::gzopen($filename, $mode);
    }

    public static function isGzHandle(int $handle): bool
    {
        return VmGzStreamNative::isNativeHandle($handle);
    }

    public static function gzwrite(int $handle, string $data, ?int $length = null): int|false
    {
        if (!VmGzStreamNative::isNativeHandle($handle)) {
            return false;
        }

        return VmGzStreamNative::gzwrite($handle, $data, $length);
    }

    public static function gzread(int $handle, int $length = 8192): string|false
    {
        if (!VmGzStreamNative::isNativeHandle($handle)) {
            return false;
        }

        return VmGzStreamNative::gzread($handle, $length);
    }

    /**
     * gzgets() — read a line from gzip stream (php-src ext/zlib/zlib.c PHP_FUNCTION(gzgets); #6290).
     */
    public static function gzgets(int $handle, int $length = 8192): string|false
    {
        if (!VmGzStreamNative::isNativeHandle($handle)) {
            return false;
        }
        if ($length <= 0) {
            return false;
        }
        $maxRead = $length - 1;
        if ($maxRead <= 0) {
            return false;
        }
        $line = '';
        while (\strlen($line) < $maxRead) {
            $byte = VmGzStreamNative::gzread($handle, 1);
            if (false === $byte) {
                return false;
            }
            if ('' === $byte) {
                return '' === $line ? false : $line;
            }
            $line .= $byte;
            if ("\n" === $byte) {
                break;
            }
        }

        return $line;
    }

    public static function gzclose(int $handle): bool
    {
        if (!VmGzStreamNative::isNativeHandle($handle)) {
            return false;
        }

        return VmGzStreamNative::gzclose($handle);
    }

    /**
     * readgzfile() — output decompressed file bytes to stdout (ext/zlib/zlib.c, #4657).
     */
    public static function readgzfile(string $filename, int $useIncludePath = 0): int|false
    {
        $handle = self::gzopen($filename, 'rb', $useIncludePath);
        if (false === $handle) {
            return false;
        }
        $total = self::passthruHandle($handle);
        self::gzclose($handle);

        return $total;
    }

    /**
     * gzfile() — read gzip file into a line array (ext/zlib/zlib.c, #4657).
     *
     * @return array|false
     */
    public static function gzfile(string $filename, int $useIncludePath = 0): array|false
    {
        $handle = self::gzopen($filename, 'rb', $useIncludePath);
        if (false === $handle) {
            return false;
        }
        $content = self::readAll($handle);
        self::gzclose($handle);
        if (false === $content) {
            return false;
        }

        return self::splitGzLines($content);
    }

    /**
     * gzpassthru() — output remaining gzip stream bytes to stdout (ext/zlib/zlib.c, #4657).
     */
    public static function gzpassthru(int $handle): int|false
    {
        if (!VmGzStreamNative::isNativeHandle($handle)) {
            return false;
        }

        return self::passthruHandle($handle);
    }

    /** @return list<string> */
    private static function splitGzLines(string $content): array
    {
        if ('' === $content) {
            return [];
        }
        $lines = [];
        $offset = 0;
        $len = \strlen($content);
        while ($offset < $len) {
            $pos = \strpos($content, "\n", $offset);
            if (false === $pos) {
                $lines[] = \substr($content, $offset);

                break;
            }
            $lines[] = \substr($content, $offset, $pos - $offset + 1);
            $offset = $pos + 1;
        }

        return $lines;
    }

    private static function readAll(int $handle): string|false
    {
        $out = '';
        while (true) {
            $chunk = self::gzread($handle, 8192);
            if (false === $chunk) {
                return false;
            }
            if ('' === $chunk) {
                break;
            }
            $out .= $chunk;
        }

        return $out;
    }

    private static function passthruHandle(int $handle): int|false
    {
        $total = 0;
        while (true) {
            $chunk = self::gzread($handle, 8192);
            if (false === $chunk) {
                return false;
            }
            if ('' === $chunk) {
                break;
            }
            $written = @\fwrite(\STDOUT, $chunk);
            if (false === $written) {
                return false;
            }
            $total += $written;
        }

        return $total;
    }
}
