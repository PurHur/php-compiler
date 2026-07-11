<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * fstat() for VM-native stream handles without host FILE* (#10460, ext/standard/filestat.c).
 *
 * php-src: ext/standard/streams.c — php_stream_stat / PHP_FUNCTION(fstat)
 */
final class VmStreamFstat
{
    /**
     * @return array<int|string, int>|false
     */
    public static function forHandle(int $handle): array|false
    {
        $fp = VmFs::lookupResource($handle);
        if (null !== $fp) {
            $raw = @fstat($fp);
            if (false === $raw) {
                return false;
            }

            return VmStatPure::normalize($raw);
        }
        if (VmPhpMemoryStream::isValidHandle($handle)) {
            $size = VmPhpMemoryStream::bufferLength($handle);
            if (false === $size) {
                return false;
            }

            return self::memoryStreamStat($size);
        }
        if (VmPhpFdStream::isValidHandle($handle)) {
            $fd = VmPhpFdStream::fdForHandle($handle);
            if (null === $fd) {
                return false;
            }

            return VmStatNative::fstatFd($fd);
        }

        return false;
    }

    /**
     * php://memory|temp synthetic stat — matches Zend php_stream_memory_stat (#10460).
     *
     * @return array<int|string, int>
     */
    public static function memoryStreamStat(int $size): array
    {
        return VmStatPure::normalize([
            'dev' => 12,
            'ino' => 0,
            'mode' => 33060,
            'nlink' => 1,
            'uid' => 0,
            'gid' => 0,
            'rdev' => -1,
            'size' => $size,
            'atime' => 0,
            'mtime' => 0,
            'ctime' => 0,
            'blksize' => -1,
            'blocks' => -1,
        ]);
    }
}
