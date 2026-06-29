<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * gzopen/gzwrite/gzread/gzclose for compiled JIT/AOT modules (#13420, php-in-PHP).
 *
 * SSOT: {@see VmGzStream}
 * php-src: ext/zlib/zlib.c
 */
final class GzStreamJitHelper
{
    /** @return int handle or -1 on failure */
    public static function gzopenArgv(string $path, string $mode, int $useIncludePath): int
    {
        $result = VmGzStream::gzopen($path, $mode, $useIncludePath);
        if (false === $result) {
            return -1;
        }

        return (int) $result;
    }

    /** @return int bytes written or -1 on failure */
    public static function gzwriteArgv(int $handle, string $data, int $length): int
    {
        if ($length >= 0 && $length < \strlen($data)) {
            $data = \substr($data, 0, $length);
        }
        $result = VmGzStream::gzwrite($handle, $data, $length >= 0 ? $length : null);
        if (false === $result) {
            return -1;
        }

        return (int) $result;
    }

    public static function gzreadArgv(int $handle, int $length): ?string
    {
        $result = VmGzStream::gzread($handle, $length);
        if (false === $result) {
            return null;
        }

        return (string) $result;
    }

    public static function gzgetsArgv(int $handle, int $length): ?string
    {
        $result = VmGzStream::gzgets($handle, $length);
        if (false === $result) {
            return null;
        }

        return (string) $result;
    }

    /** @return 0|1 */
    public static function gzcloseArgv(int $handle): int
    {
        return VmGzStream::gzclose($handle) ? 1 : 0;
    }

    public static function gzReadAllArgv(int $handle): ?string
    {
        $out = '';
        while (true) {
            $chunk = VmGzStream::gzread($handle, 8192);
            if (false === $chunk) {
                return null;
            }
            if ('' === $chunk) {
                break;
            }
            $out .= $chunk;
        }

        return $out;
    }

    /** @return int bytes passed through or -1 on failure */
    public static function gzPassthruArgv(int $handle): int
    {
        $result = VmGzStream::gzpassthru($handle);
        if (false === $result) {
            return -1;
        }

        return (int) $result;
    }
}
