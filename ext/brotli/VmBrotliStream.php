<?php

declare(strict_types=1);

namespace PHPCompiler\ext\brotli;

use PHPCompiler\ext\standard\VmDataUri;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmFsReadNative;

/**
 * compress.brotli:// stream wrapper — buffered brotli I/O via {@see VmBrotliNative} (#28115).
 *
 * Write path buffers plaintext and compresses on close; read path loads and decompresses once.
 * PECL: kjdev/php-ext-brotli brotli.c — php_stream_brotli_opener / php_stream_brotli_wrapper
 */
final class VmBrotliStream
{
    public const PROTOCOL = 'compress.brotli';

    private const PREFIX = 'compress.brotli://';

    /** @var array<int, array{path: string, writing: bool, quality: int, buffer: string, pos: int, eof: bool}> */
    private static array $streams = [];

    public static function available(): bool
    {
        return BrotliExtensionPolicy::advertisesExtension();
    }

    public static function isSupportedUri(string $uri): bool
    {
        return self::available() && str_starts_with(\strtolower($uri), self::PREFIX);
    }

    public static function isValidHandle(int $handle): bool
    {
        return isset(self::$streams[$handle]);
    }

    /**
     * @return int|false VM stream handle
     */
    public static function open(string $uri, string $mode, ?int $quality = null): int|false
    {
        if (!self::available()) {
            return false;
        }
        $parsed = self::parseMode($mode);
        if (null === $parsed) {
            return false;
        }

        $innerPath = self::stripWrapper($uri);
        if ('' === $innerPath || str_contains($innerPath, "\0")) {
            return false;
        }

        $level = $quality ?? VmBrotliNative::DEFAULT_QUALITY;
        if ($level < VmBrotliNative::MIN_QUALITY || $level > VmBrotliNative::MAX_QUALITY) {
            $level = VmBrotliNative::DEFAULT_QUALITY;
        }

        $buffer = '';
        $pos = 0;
        $eof = false;
        if (!$parsed['writing']) {
            $raw = self::loadReadPayload($innerPath);
            if (false === $raw) {
                return false;
            }
            if ('' === $raw) {
                $buffer = '';
            } else {
                $decoded = VmBrotliNative::uncompress($raw);
                if (false === $decoded) {
                    return false;
                }
                $buffer = $decoded;
            }
        }

        $id = VmFs::allocateStreamHandleId();
        self::$streams[$id] = [
            'path' => $innerPath,
            'writing' => $parsed['writing'],
            'quality' => $level,
            'buffer' => $buffer,
            'pos' => $pos,
            'eof' => $eof,
        ];
        VmFs::registerStreamPath($id, self::PREFIX.$innerPath);
        VmFs::registerStreamMode($id, $mode);

        return $id;
    }

    public static function read(int $handle, int $length): string|false
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
            $stream['eof'] = true;
            self::$streams[$handle] = $stream;

            return '';
        }
        $take = \min($length, $remaining);
        $chunk = \substr($stream['buffer'], $stream['pos'], $take);
        $stream['pos'] += $take;
        if ($stream['pos'] >= \strlen($stream['buffer'])) {
            $stream['eof'] = true;
        }
        self::$streams[$handle] = $stream;

        return $chunk;
    }

    public static function write(int $handle, string $data, ?int $length = null): int|false
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

    public static function eof(int $handle): bool
    {
        $stream = self::$streams[$handle] ?? null;
        if (null === $stream) {
            return true;
        }
        if ($stream['writing']) {
            return false;
        }

        return $stream['eof'];
    }

    public static function tell(int $handle): int|false
    {
        $stream = self::$streams[$handle] ?? null;
        if (null === $stream || $stream['writing']) {
            return false;
        }

        return $stream['pos'];
    }

    public static function seek(int $handle, int $offset, int $whence = \SEEK_SET): int
    {
        $stream = self::$streams[$handle] ?? null;
        if (null === $stream || $stream['writing']) {
            return -1;
        }
        $len = \strlen($stream['buffer']);
        $pos = match ($whence) {
            \SEEK_SET => $offset,
            \SEEK_CUR => $stream['pos'] + $offset,
            \SEEK_END => $len + $offset,
            default => -1,
        };
        if ($pos < 0) {
            return -1;
        }
        $stream['pos'] = $pos;
        $stream['eof'] = false;
        self::$streams[$handle] = $stream;

        return 0;
    }

    /**
     * @return string|false
     */
    public static function streamGetContents(int $handle, int $maxlength = -1, int $offset = -1)
    {
        if ($offset >= 0 && 0 !== self::seek($handle, $offset, \SEEK_SET)) {
            return false;
        }
        if ($maxlength === 0) {
            return '';
        }
        if ($maxlength < 0) {
            $parts = [];
            while (true) {
                $chunk = self::read($handle, 8192);
                if (false === $chunk) {
                    return false;
                }
                if ('' === $chunk) {
                    break;
                }
                $parts[] = $chunk;
            }

            return '' === $parts ? '' : \implode('', $parts);
        }
        $out = '';
        while (\strlen($out) < $maxlength) {
            $chunk = self::read($handle, $maxlength - \strlen($out));
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

    public static function close(int $handle): bool
    {
        $stream = self::$streams[$handle] ?? null;
        unset(self::$streams[$handle]);
        if (null === $stream) {
            return false;
        }
        if (!$stream['writing']) {
            return true;
        }

        $compressed = VmBrotliNative::compress($stream['buffer'], $stream['quality']);
        if (false === $compressed) {
            return false;
        }
        $written = VmFs::filePutContents($stream['path'], $compressed, 0);

        return false !== $written;
    }

    /** @internal PHPUnit isolation */
    public static function resetForTests(): void
    {
        self::$streams = [];
    }

    /**
     * @return array{writing: bool}|null
     */
    private static function parseMode(string $mode): ?array
    {
        // pecl brotli.c — only "r"/"rb" and "w"/"wb"
        if ('r' === $mode || 'rb' === $mode) {
            return ['writing' => false];
        }
        if ('w' === $mode || 'wb' === $mode) {
            return ['writing' => true];
        }

        return null;
    }

    private static function stripWrapper(string $path): string
    {
        if (str_starts_with(\strtolower($path), self::PREFIX)) {
            return \substr($path, \strlen(self::PREFIX));
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
        if (!\is_readable($path) || !\is_file($path)) {
            return false;
        }

        return VmFsReadNative::read($path);
    }
}
