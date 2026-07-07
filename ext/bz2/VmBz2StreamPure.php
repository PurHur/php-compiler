<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bz2;

use PHPCompiler\ext\standard\VmDataUri;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmFsReadNative;

/**
 * VM bz* stream I/O via buffered plaintext + {@see VmBz2Core} — no libbz2 FFI (#17301).
 *
 * Write path buffers plaintext and bzcompresses on close; read path loads the file and bzdecompresses once.
 * php-src: ext/bz2/bz2.c — php_bz2open / php_bz2read / php_bz2write / php_bz2close
 */
final class VmBz2StreamPure
{
    /** @var array<int, array{path: string, writing: bool, append: bool, blockSize: int, buffer: string, pos: int, eof: bool}> */
    private static array $streams = [];

    public static function available(): bool
    {
        return VmBz2Core::available();
    }

    public static function isHandle(int $handle): bool
    {
        return isset(self::$streams[$handle]);
    }

    public static function bzopen(string $filename, string $mode): int|false
    {
        $parsed = self::parseMode($mode);
        if (null === $parsed) {
            return false;
        }

        $innerPath = self::stripCompressBzip2Wrapper($filename);

        if ('php://memory' === $innerPath) {
            return false;
        }

        $buffer = '';
        $pos = 0;
        if (!$parsed['writing']) {
            $raw = self::loadReadPayload($innerPath);
            if (false === $raw) {
                return false;
            }
            if ('' === $raw) {
                $buffer = '';
            } else {
                $decoded = VmBz2Core::decompress($raw);
                $buffer = false !== $decoded ? $decoded : $raw;
            }
        } elseif ($parsed['append'] && self::isReadableRegularFile($innerPath)) {
            $raw = self::loadReadPayload($innerPath);
            if (false !== $raw && '' !== $raw) {
                $decoded = VmBz2Core::decompress($raw);
                if (false !== $decoded) {
                    $buffer = $decoded;
                }
            }
        }

        $id = VmFs::adoptBz2NativePlaceholder('compress.bzip2://'.$innerPath);
        if (false === $id) {
            return false;
        }

        self::$streams[$id] = [
            'path' => $innerPath,
            'writing' => $parsed['writing'],
            'append' => $parsed['append'],
            'blockSize' => $parsed['blockSize'],
            'buffer' => $buffer,
            'pos' => $pos,
            'eof' => false,
        ];

        return $id;
    }

    public static function bzwrite(int $handle, string $data, ?int $length = null): int|false
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

    public static function bzread(int $handle, int $length = 4096): string|false
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
        self::$streams[$handle] = $stream;

        return $chunk;
    }

    public static function bzclose(int $handle): bool
    {
        $stream = self::$streams[$handle] ?? null;
        unset(self::$streams[$handle]);
        VmFs::releaseBz2NativePlaceholder($handle);
        if (null === $stream) {
            return false;
        }
        if (!$stream['writing']) {
            return true;
        }

        $compressed = VmBz2Core::compress($stream['buffer'], $stream['blockSize']);
        if (false === $compressed) {
            return false;
        }
        $flags = $stream['append'] ? \FILE_APPEND : 0;
        $written = VmFs::filePutContents($stream['path'], $compressed, $flags);

        return false !== $written;
    }

    /**
     * @return array{writing: bool, append: bool, blockSize: int}|null
     */
    private static function parseMode(string $mode): ?array
    {
        if ('' === $mode) {
            return null;
        }
        if (str_contains($mode, '+')) {
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
        $blockSize = 4;
        if ('' !== $rest) {
            if (!ctype_digit($rest)) {
                return null;
            }
            $blockSize = (int) $rest;
            if ($blockSize < 1 || $blockSize > 9) {
                return null;
            }
            if (!$writing) {
                return null;
            }
        }

        return [
            'writing' => $writing,
            'append' => $append,
            'blockSize' => $blockSize,
        ];
    }

    private static function stripCompressBzip2Wrapper(string $path): string
    {
        $prefix = 'compress.bzip2://';
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
        if (self::isPhpTempWrapperUri($path)) {
            $payload = VmFs::readPathContentsViaOpen($path);

            return false !== $payload ? $payload : false;
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

    private static function isPhpTempWrapperUri(string $path): bool
    {
        return \str_starts_with($path, 'php://temp');
    }
}
