<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM gz* stream I/O via buffered plaintext + {@see VmZlib} — no libz gzFile FFI (#8936, #6168).
 *
 * Write path buffers plaintext and gzencodes on close; read path loads the file and gzdecodes once.
 * php-src: ext/zlib/zlib.c — php_gzopen / php_stream_gzops
 */
final class VmGzStreamPure
{
    /** @var array<int, array{path: string, writing: bool, append: bool, level: int, buffer: string, pos: int}> */
    private static array $streams = [];

    public static function available(): bool
    {
        return VmZlibCore::available();
    }

    public static function isHandle(int $handle): bool
    {
        return isset(self::$streams[$handle]);
    }

    public static function gzopen(string $filename, string $mode): int|false
    {
        $parsed = self::parseMode($mode);
        if (null === $parsed) {
            return false;
        }

        $innerPath = self::stripCompressZlibWrapper($filename);

        $buffer = '';
        $pos = 0;
        if (!$parsed['writing']) {
            $raw = self::loadReadPayload($innerPath);
            if (false === $raw || '' === $raw) {
                return false;
            }
            $decoded = VmZlib::gzdecode($raw);
            $buffer = false !== $decoded ? $decoded : $raw;
        } elseif ($parsed['append'] && self::isReadableRegularFile($innerPath)) {
            $raw = self::loadReadPayload($innerPath);
            if (false !== $raw && '' !== $raw) {
                $decoded = VmZlib::gzdecode($raw);
                if (false !== $decoded) {
                    $buffer = $decoded;
                }
            }
        }

        $id = VmFs::adoptGzNativePlaceholder('compress.zlib://'.$innerPath);
        if (false === $id) {
            return false;
        }

        self::$streams[$id] = [
            'path' => $innerPath,
            'writing' => $parsed['writing'],
            'append' => $parsed['append'],
            'level' => $parsed['level'],
            'buffer' => $buffer,
            'pos' => $pos,
        ];

        return $id;
    }

    public static function gzwrite(int $handle, string $data, ?int $length = null): int|false
    {
        $stream = self::$streams[$handle] ?? null;
        if (null === $stream || !$stream['writing']) {
            return false;
        }
        if (null !== $length) {
            if ($length < 0) {
                return false;
            }
            if ($length < \strlen($data)) {
                $data = \substr($data, 0, $length);
            }
        }
        if ('' === $data) {
            return 0;
        }
        $stream['buffer'] .= $data;
        self::$streams[$handle] = $stream;

        return \strlen($data);
    }

    public static function gzread(int $handle, int $length = 8192): string|false
    {
        $stream = self::$streams[$handle] ?? null;
        if (null === $stream || $stream['writing']) {
            return false;
        }
        if ($length < 0) {
            return false;
        }
        if (0 === $length) {
            return '';
        }
        $remaining = \strlen($stream['buffer']) - $stream['pos'];
        if ($remaining <= 0) {
            return '';
        }
        $take = \min($length, $remaining);
        $chunk = \substr($stream['buffer'], $stream['pos'], $take);
        $stream['pos'] += $take;
        self::$streams[$handle] = $stream;

        return $chunk;
    }

    public static function gzclose(int $handle): bool
    {
        $stream = self::$streams[$handle] ?? null;
        unset(self::$streams[$handle]);
        VmFs::releaseGzNativePlaceholder($handle);
        if (null === $stream) {
            return false;
        }
        if (!$stream['writing']) {
            return true;
        }

        $compressed = VmZlib::gzencode($stream['buffer'], $stream['level'], \ZLIB_ENCODING_GZIP);
        if (false === $compressed) {
            return false;
        }
        $flags = $stream['append'] ? \FILE_APPEND : 0;
        $written = VmFs::filePutContents($stream['path'], $compressed, $flags);

        return false !== $written;
    }

    /**
     * @return array{writing: bool, append: bool, level: int}|null
     */
    private static function parseMode(string $mode): ?array
    {
        if ('' === $mode) {
            return null;
        }
        $first = $mode[0];
        $writing = false;
        $append = false;
        if ('r' === $first) {
            $writing = false;
        } elseif ('w' === $first) {
            $writing = true;
        } elseif ('a' === $first) {
            $writing = true;
            $append = true;
        } else {
            return null;
        }

        $rest = \substr($mode, 1);
        if (str_contains($rest, 'b')) {
            $rest = str_replace('b', '', $rest);
        }
        $level = -1;
        if ('' !== $rest) {
            if (!ctype_digit($rest)) {
                return null;
            }
            $level = (int) $rest;
            if ($level < 0 || $level > 9) {
                return null;
            }
            if (!$writing) {
                return null;
            }
        }

        return [
            'writing' => $writing,
            'append' => $append,
            'level' => $level,
        ];
    }

    private static function stripCompressZlibWrapper(string $path): string
    {
        $prefix = 'compress.zlib://';
        if (str_starts_with($path, $prefix)) {
            return substr($path, \strlen($prefix));
        }

        return $path;
    }

    private static function loadReadPayload(string $path): string|false
    {
        if (str_contains($path, "\0")) {
            return false;
        }
        if (VmDataUri::isDataUri($path)) {
            return VmDataUri::decode($path);
        }
        if (!self::isReadableRegularFile($path)) {
            return false;
        }

        return VmFsReadNative::read($path);
    }

    private static function isReadableRegularFile(string $path): bool
    {
        if (str_contains($path, "\0")) {
            return false;
        }

        return \is_readable($path) && \is_file($path);
    }
}
