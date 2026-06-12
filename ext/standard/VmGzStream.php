<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * gzopen/gzwrite/gzread/gzclose — zlib stream resource API (ext/zlib/zlib.c, #6168).
 *
 * VM delegates to host libz via PHP ext/zlib; JIT/AOT via {@see \PHPCompiler\JIT\Builtin\GzStreamIoJit}.
 */
final class VmGzStream
{
    /** @var array<int, true> */
    private static array $gzHandles = [];

    public static function gzopen(string $filename, string $mode, int $useIncludePath = 0): int|false
    {
        if (!\function_exists('gzopen')) {
            return false;
        }
        if (0 !== ($useIncludePath & 1)) {
            $resolved = VmFs::resolveIncludePath($filename);
            if (false !== $resolved) {
                $filename = $resolved;
            }
        }
        $fp = @\gzopen($filename, $mode);
        if (false === $fp) {
            return false;
        }
        $id = VmFs::adoptStreamResource($fp, 'compress.zlib://'.$filename);
        if (false === $id) {
            @\gzclose($fp);

            return false;
        }
        self::$gzHandles[$id] = true;

        return $id;
    }

    public static function isGzHandle(int $handle): bool
    {
        return isset(self::$gzHandles[$handle]);
    }

    public static function gzwrite(int $handle, string $data, ?int $length = null): int|false
    {
        if (!self::isGzHandle($handle)) {
            return false;
        }
        $fp = VmFs::lookupResource($handle);
        if (null === $fp) {
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
        $written = @\gzwrite($fp, $data);
        if (false === $written) {
            return false;
        }

        return (int) $written;
    }

    public static function gzread(int $handle, int $length = 8192): string|false
    {
        if (!self::isGzHandle($handle)) {
            return false;
        }
        $fp = VmFs::lookupResource($handle);
        if (null === $fp) {
            return false;
        }
        if ($length < 0) {
            return false;
        }
        $data = @\gzread($fp, $length);
        if (false === $data) {
            return false;
        }

        return $data;
    }

    public static function gzclose(int $handle): bool
    {
        if (!self::isGzHandle($handle)) {
            return false;
        }
        $fp = VmFs::detachStreamHandle($handle);
        unset(self::$gzHandles[$handle]);
        if (null === $fp) {
            return false;
        }

        return @\gzclose($fp);
    }
}
