<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bz2;

use PHPCompiler\ext\standard\VmDataUri;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmFsReadNative;

/**
 * bzopen/bzread/bzwrite/bzclose — buffered bzip2 stream I/O via {@see VmBz2Core} (#17301).
 *
 * Write path buffers plaintext and compresses on close; read path loads and decompresses once.
 * php-src: ext/bz2/bz2.c — php_bz2open / php_bz2read / php_bz2write / php_bz2close
 */
final class VmBz2StreamPure
{
    /** @var array<int, array{path: string, writing: bool, blockSize: int, buffer: string, pos: int, errno: int}> */
    private static array $streams = [];

    public static function available(): bool
    {
        return VmBz2Core::available();
    }

    public static function isHandle(int $handle): bool
    {
        return isset(self::$streams[$handle]);
    }

    public static function getErrno(int $handle): int
    {
        return self::$streams[$handle]['errno'] ?? VmBz2Error::BZ_OK;
    }

    public static function setErrno(int $handle, int $errno): void
    {
        if (!isset(self::$streams[$handle])) {
            return;
        }
        self::$streams[$handle]['errno'] = $errno;
    }

    public static function bzopen(string $filename, string $mode): int|false
    {
        $parsed = self::parseMode($mode);
        if (null === $parsed) {
            return false;
        }

        $innerPath = self::stripCompressBzip2Wrapper($filename);

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
                $decoded = VmBz2Native::decompress($raw);
                if (false === $decoded) {
                    return false;
                }
                $buffer = $decoded;
            }
        }

        $id = VmFs::adoptBzNativePlaceholder('compress.bzip2://'.$innerPath);
        if (false === $id) {
            return false;
        }

        self::$streams[$id] = [
            'path' => $innerPath,
            'writing' => $parsed['writing'],
            'blockSize' => $parsed['blockSize'],
            'buffer' => $buffer,
            'pos' => $pos,
            'errno' => VmBz2Error::BZ_OK,
        ];

        return $id;
    }

    public static function bzwrite(int $handle, string $data, ?int $length = null): int|false
    {
        $stream = self::$streams[$handle] ?? null;
        if (null === $stream || !$stream['writing']) {
            if (null !== $stream) {
                self::setErrno($handle, VmBz2Error::BZ_SEQUENCE_ERROR);
            }

            return false;
        }
        if (null !== $length) {
            if ($length < 0) {
                self::setErrno($handle, VmBz2Error::BZ_PARAM_ERROR);

                return false;
            }
            if ($length < \strlen($data)) {
                $data = \substr($data, 0, $length);
            }
        }
        if ('' === $data) {
            self::setErrno($handle, VmBz2Error::BZ_OK);

            return 0;
        }
        $stream['buffer'] .= $data;
        $stream['errno'] = VmBz2Error::BZ_OK;
        self::$streams[$handle] = $stream;

        return \strlen($data);
    }

    public static function bzread(int $handle, int $length = 1024): string|false
    {
        $stream = self::$streams[$handle] ?? null;
        if (null === $stream || $stream['writing']) {
            if (null !== $stream) {
                self::setErrno($handle, VmBz2Error::BZ_SEQUENCE_ERROR);
            }

            return false;
        }
        if ($length < 0) {
            self::setErrno($handle, VmBz2Error::BZ_PARAM_ERROR);

            return false;
        }
        if (0 === $length) {
            self::setErrno($handle, VmBz2Error::BZ_OK);

            return '';
        }
        $remaining = \strlen($stream['buffer']) - $stream['pos'];
        if ($remaining <= 0) {
            self::setErrno($handle, VmBz2Error::BZ_OK);

            return '';
        }
        $take = \min($length, $remaining);
        $chunk = \substr($stream['buffer'], $stream['pos'], $take);
        $stream['pos'] += $take;
        $stream['errno'] = VmBz2Error::BZ_OK;
        self::$streams[$handle] = $stream;

        return $chunk;
    }

    public static function bzclose(int $handle): bool
    {
        $stream = self::$streams[$handle] ?? null;
        unset(self::$streams[$handle]);
        VmFs::releaseBzNativePlaceholder($handle);
        if (null === $stream) {
            return false;
        }
        if (!$stream['writing']) {
            return true;
        }

        $compressed = VmBz2Native::compress($stream['buffer'], $stream['blockSize']);
        if (false === $compressed) {
            return false;
        }
        $written = VmFs::filePutContents($stream['path'], $compressed, 0);

        return false !== $written;
    }

    /**
     * @return array{writing: bool, blockSize: int}|null
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
        if ('r' === $first) {
            $writing = false;
            $rest = \substr($mode, 1);
            if ('' !== $rest && 'b' !== $rest) {
                return null;
            }

            return ['writing' => $writing, 'blockSize' => 4];
        }
        if ('w' === $first) {
            $blockSize = 4;
            $rest = \substr($mode, 1);
            if ('' !== $rest) {
                if ('b' === $rest) {
                    return ['writing' => true, 'blockSize' => $blockSize];
                }
                if (1 === \strlen($rest) && $rest >= '1' && $rest <= '9') {
                    $blockSize = (int) $rest;
                } else {
                    return null;
                }
            }

            return ['writing' => true, 'blockSize' => $blockSize];
        }

        return null;
    }

    private static function stripCompressBzip2Wrapper(string $path): string
    {
        $prefix = 'compress.bzip2://';
        if (str_starts_with($path, $prefix)) {
            return \substr($path, \strlen($prefix));
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
