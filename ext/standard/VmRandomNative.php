<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * CSPRNG via libc getrandom(2) with /dev/urandom open(2)/read(2) fallback — no host PHP I/O (#8402).
 *
 * Mirrors {@see \PHPCompiler\JIT\Builtin\StringRandomBytes} (/dev/urandom LLVM read) and
 * {@see VmFsReadNative} FFI patterns. php-src: ext/standard/random.c — php_random_bytes()
 */
final class VmRandomNative
{
    private const O_RDONLY = 0;

    private const CHUNK = 8192;

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi() || VmRandomPure::available();
    }

    /**
     * @throws \Exception when the operating system cannot supply random data
     */
    public static function randomBytes(int $length): string
    {
        if ($length < 1) {
            throw new \ValueError('random_bytes(): Argument #1 ($length) must be greater than 0');
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return VmRandomPure::randomBytes($length);
        }

        $viaGetrandom = self::tryGetrandom($ffi, $length);
        if (null !== $viaGetrandom) {
            return $viaGetrandom;
        }

        $viaUrandom = self::readUrandom($ffi, $length);
        if (false === $viaUrandom || \strlen($viaUrandom) !== $length) {
            throw new \Exception('Could not gather sufficient random data');
        }

        return $viaUrandom;
    }

    private static function tryGetrandom(\FFI $ffi, int $length): ?string
    {
        try {
            $buf = $ffi->new('char['.$length.']');
            $total = 0;
            while ($total < $length) {
                $remain = $length - $total;
                $ret = (int) $ffi->getrandom(\FFI::addr($buf[$total]), $remain, 0);
                if ($ret <= 0) {
                    return null;
                }
                $total += $ret;
            }

            return \FFI::string($buf, $length);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function readUrandom(\FFI $ffi, int $length): string|false
    {
        $fd = (int) $ffi->open('/dev/urandom', self::O_RDONLY);
        if ($fd < 0) {
            return false;
        }

        $parts = [];
        $remaining = $length;
        $scratch = $ffi->new('char['.self::CHUNK.']');
        while ($remaining > 0) {
            $want = min(self::CHUNK, $remaining);
            $n = (int) $ffi->read($fd, \FFI::addr($scratch[0]), $want);
            if ($n <= 0) {
                $ffi->close($fd);

                return false;
            }
            $parts[] = \FFI::string($scratch, $n);
            $remaining -= $n;
        }
        $ffi->close($fd);

        return implode('', $parts);
    }

    private static function ffiEnabled(): bool
    {
        $v = getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== strtolower($v)) {
            return false;
        }

        return true;
    }

    private static function ffi(): ?\FFI
    {
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (self::$ffiUnavailable || !self::ffiEnabled() || !\extension_loaded('ffi')) {
            self::$ffiUnavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
typedef long ssize_t;
typedef unsigned long size_t;
ssize_t getrandom(void *buf, size_t buflen, unsigned int flags);
int open(const char *pathname, int flags, ...);
ssize_t read(int fd, void *buf, size_t count);
int close(int fd);
CDEF;

        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        self::$ffiUnavailable = true;

        return null;
    }
}
