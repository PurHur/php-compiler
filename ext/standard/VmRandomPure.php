<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM random_bytes() without libc getrandom/open FFI (#8921, #12181).
 *
 * Reads /dev/urandom via {@see VmFsReadNative} / {@see VmFsReadPure} stream paths;
 * host fopen/fread bootstrap when compiled I/O unavailable (pairs {@see VmUnamePure}).
 *
 * php-src: ext/standard/random.c — php_random_bytes()
 */
final class VmRandomPure
{
    private const URANDOM = '/dev/urandom';

    private const CHUNK = 8192;

    public static function available(): bool
    {
        return \is_readable(self::URANDOM);
    }

    /**
     * @throws \ValueError when $length < 1
     * @throws \Exception when the operating system cannot supply random data
     */
    public static function randomBytes(int $length): string
    {
        if ($length < 1) {
            throw new \ValueError('random_bytes(): Argument #1 ($length) must be greater than 0');
        }

        $data = self::readUrandom($length);
        if (false === $data || \strlen($data) !== $length) {
            throw new \Exception('Could not gather sufficient random data');
        }

        return $data;
    }

    private static function readUrandom(int $length): string|false
    {
        if (VmFsReadNative::available()) {
            $viaNative = VmFsReadNative::readSlice(self::URANDOM, 0, $length);
            if (false !== $viaNative && \strlen($viaNative) === $length) {
                return $viaNative;
            }
        }

        return self::readUrandomHost($length);
    }

    private static function readUrandomHost(int $length): string|false
    {
        if (!\is_readable(self::URANDOM)) {
            return false;
        }

        $fp = @\fopen(self::URANDOM, 'rb');
        if (false === $fp) {
            return false;
        }

        $parts = [];
        $remaining = $length;
        while ($remaining > 0) {
            $want = min(self::CHUNK, $remaining);
            $chunk = @\fread($fp, $want);
            if (false === $chunk || '' === $chunk) {
                @\fclose($fp);

                return false;
            }
            $parts[] = $chunk;
            $remaining -= \strlen($chunk);
        }
        @\fclose($fp);

        return implode('', $parts);
    }
}
