<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bz2;

/**
 * bzopen/bzread/bzwrite/bzclose for compiled JIT/AOT modules (#17301, php-in-PHP).
 *
 * SSOT: {@see VmBz2Stream}
 * php-src: ext/bz2/bz2.c
 */
final class Bz2StreamJitHelper
{
    /** @return int handle or -1 on failure */
    public static function bzopenArgv(string $path, string $mode): int
    {
        $result = VmBz2Stream::bzopen($path, $mode);
        if (false === $result) {
            return -1;
        }

        return (int) $result;
    }

    /** @return int bytes written or -1 on failure */
    public static function bzwriteArgv(int $handle, string $data, int $length): int
    {
        if ($length >= 0 && $length < \strlen($data)) {
            $data = \substr($data, 0, $length);
        }
        $result = VmBz2Stream::bzwrite($handle, $data, $length >= 0 ? $length : null);
        if (false === $result) {
            return -1;
        }

        return (int) $result;
    }

    public static function bzreadArgv(int $handle, int $length): ?string
    {
        $result = VmBz2Stream::bzread($handle, $length);
        if (false === $result) {
            return null;
        }

        return (string) $result;
    }

    /** @return 0|1 */
    public static function bzcloseArgv(int $handle): int
    {
        return VmBz2Stream::bzclose($handle) ? 1 : 0;
    }

    public static function bzerrnoArgv(int $handle): int
    {
        if (!VmBz2Stream::isBzHandle($handle)) {
            throw new \TypeError('bzerrno(): Argument #1 ($bz) must be a bz2 stream');
        }

        return VmBz2Error::errno($handle);
    }

    public static function bzerrstrArgv(int $handle): string
    {
        if (!VmBz2Stream::isBzHandle($handle)) {
            throw new \TypeError('bzerrstr(): Argument #1 ($bz) must be a bz2 stream');
        }

        return VmBz2Error::errstr($handle);
    }

    /** @return array{errno: int, errstr: string} */
    public static function bzerrorArgv(int $handle): array
    {
        if (!VmBz2Stream::isBzHandle($handle)) {
            throw new \TypeError('bzerror(): Argument #1 ($bz) must be a bz2 stream');
        }

        return VmBz2Error::error($handle);
    }

    /** @return 0|1 */
    public static function bzflushArgv(int $handle): int
    {
        return VmBz2Error::flush($handle) ? 1 : 0;
    }
}
