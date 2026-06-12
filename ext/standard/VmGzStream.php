<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * gzopen/gzwrite/gzread/gzclose — zlib stream resource API (ext/zlib/zlib.c, #6168).
 *
 * VM via {@see VmGzStreamNative} libz FFI — no host ext/zlib gz* delegation (#8220).
 * JIT/AOT via {@see \PHPCompiler\JIT\Builtin\GzStreamIoJit}.
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

    public static function gzclose(int $handle): bool
    {
        if (!VmGzStreamNative::isNativeHandle($handle)) {
            return false;
        }

        return VmGzStreamNative::gzclose($handle);
    }
}
