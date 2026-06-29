<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\ext\posix\PosixConstants;

/**
 * Host clock tick rate (sysconf _SC_CLK_TCK) SSOT for getrusage/posix_times (#13522, #13524).
 *
 * php-src: unistd.h _SC_CLK_TCK — used by getrusage(2) / times(2) timeval conversion.
 */
final class VmProcClockTicksPure
{
    /** Linux glibc _SC_CLK_TCK. */
    private const SC_CLK_TCK = 2;

    /** ELF aux vector AT_CLKTCK. */
    private const AT_CLKTCK = 17;

    private static ?int $cached = null;

    public static function clockTicksPerSecond(): int
    {
        if (null !== self::$cached) {
            return self::$cached;
        }

        $override = \getenv('PHP_COMPILER_PROC_CLK_TCK');
        if (false !== $override && '' !== $override) {
            $n = (int) $override;
            if ($n > 0) {
                return self::$cached = $n;
            }
        }

        $fromSysconf = self::readSysconfClkTck();
        if (null !== $fromSysconf && $fromSysconf > 0) {
            return self::$cached = $fromSysconf;
        }

        $fromAuxv = self::readAuxvClkTck();
        if (null !== $fromAuxv && $fromAuxv > 0) {
            return self::$cached = $fromAuxv;
        }

        return self::$cached = PosixConstants::CLK_TCK_FALLBACK;
    }

    public static function resetCacheForTests(): void
    {
        self::$cached = null;
    }

    private static function readSysconfClkTck(): ?int
    {
        $ffi = self::sysconfFfi();
        if (null === $ffi) {
            return null;
        }

        $hz = (int) $ffi->sysconf(self::SC_CLK_TCK);

        return $hz > 0 ? $hz : null;
    }

    private static function readAuxvClkTck(): ?int
    {
        if ('Linux' !== \PHP_OS_FAMILY || !\is_readable('/proc/self/auxv')) {
            return null;
        }

        $raw = @\file_get_contents('/proc/self/auxv');
        if (!\is_string($raw) || '' === $raw) {
            return null;
        }

        $entryBytes = 8 === \PHP_INT_SIZE ? 16 : 8;
        $len = \strlen($raw);
        for ($off = 0; $off + $entryBytes <= $len; $off += $entryBytes) {
            $type = self::unpackAuxWord(\substr($raw, $off, 8));
            if (0 === $type) {
                break;
            }
            if (self::AT_CLKTCK === $type) {
                $value = self::unpackAuxWord(\substr($raw, $off + 8, 8));

                return $value > 0 ? $value : null;
            }
        }

        return null;
    }

    private static function unpackAuxWord(string $bytes): int
    {
        if (8 === \PHP_INT_SIZE) {
            $unpacked = \unpack('P', $bytes);

            return \is_array($unpacked) ? (int) ($unpacked[1] ?? 0) : 0;
        }

        $unpacked = \unpack('V', $bytes);

        return \is_array($unpacked) ? (int) ($unpacked[1] ?? 0) : 0;
    }

    private static function sysconfFfi(): ?\FFI
    {
        static $ffi = null;
        static $unavailable = false;

        if ($unavailable) {
            return null;
        }
        if (null !== $ffi) {
            return $ffi;
        }
        if (!self::ffiEnabled() || !\class_exists(\FFI::class, false)) {
            $unavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
long sysconf(int name);
CDEF;

        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                $ffi = \FFI::cdef($cdef, $lib);

                return $ffi;
            } catch (\Throwable) {
            }
        }

        $unavailable = true;

        return null;
    }

    private static function ffiEnabled(): bool
    {
        $v = \getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== \strtolower($v)) {
            return false;
        }

        return true;
    }
}
