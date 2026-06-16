<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM sleep/usleep/time_nanosleep/time_sleep_until via libc FFI (#4860, #5406).
 *
 * Mirrors {@see \PHPCompiler\JIT\Builtin\TimeSleepRuntime} and {@see JitSleep} — no host
 * \\sleep()/\\usleep()/\\time_nanosleep() delegation on VM.
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(sleep), usleep, time_nanosleep, time_sleep_until
 */
final class VmSleepNative
{
    private const NS_PER_SEC = 1_000_000_000;

    private const EINTR = 4;

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    public static function sleep(int $seconds): int|false
    {
        if ($seconds < 0) {
            throw new \ValueError('sleep(): Argument #1 ($seconds) must be greater than or equal to 0');
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return VmSleepPure::sleep($seconds);
        }

        return (int) $ffi->sleep($seconds);
    }

    public static function usleep(int $microseconds): void
    {
        if ($microseconds < 0) {
            throw new \ValueError('usleep(): Argument #1 ($microseconds) must be greater than or equal to 0');
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            VmSleepPure::usleep($microseconds);

            return;
        }
        if (0 === $microseconds) {
            return;
        }

        $remaining = $microseconds;
        while ($remaining > 0) {
            $chunk = $remaining > 999_999 ? 999_999 : $remaining;
            if (0 !== (int) $ffi->usleep($chunk)) {
                return;
            }
            $remaining -= $chunk;
        }
    }

    /** @return true|array{seconds: int, nanoseconds: int}|false */
    public static function timeNanosleep(int $seconds, int $nanoseconds): mixed
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return VmSleepPure::timeNanosleep($seconds, $nanoseconds);
        }

        $req = $ffi->new('struct timespec');
        $rem = $ffi->new('struct timespec');
        $req->tv_sec = $seconds;
        $req->tv_nsec = $nanoseconds;

        while (true) {
            $ret = (int) $ffi->nanosleep(\FFI::addr($req), \FFI::addr($rem));
            if (0 === $ret) {
                return true;
            }
            if (self::EINTR === self::errno($ffi)) {
                $req->tv_sec = $rem->tv_sec;
                $req->tv_nsec = $rem->tv_nsec;

                continue;
            }

            return [
                'seconds' => (int) $rem->tv_sec,
                'nanoseconds' => (int) $rem->tv_nsec,
            ];
        }
    }

    public static function timeSleepUntil(float $timestamp): bool
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return VmSleepPure::timeSleepUntil($timestamp);
        }

        $tv = $ffi->new('struct timeval');
        if (0 !== (int) $ffi->gettimeofday(\FFI::addr($tv), null)) {
            return false;
        }

        $currentNs = (float) $tv->tv_sec * (float) self::NS_PER_SEC + (float) $tv->tv_usec * 1000.0;
        $targetNs = $timestamp * (float) self::NS_PER_SEC;
        if ($targetNs < $currentNs) {
            return false;
        }

        $diffNs = (int) round($targetNs - $currentNs);
        $sleepSec = intdiv($diffNs, self::NS_PER_SEC);
        $sleepNsec = $diffNs % self::NS_PER_SEC;

        $req = $ffi->new('struct timespec');
        $rem = $ffi->new('struct timespec');
        $req->tv_sec = $sleepSec;
        $req->tv_nsec = $sleepNsec;

        while (true) {
            $ret = (int) $ffi->nanosleep(\FFI::addr($req), \FFI::addr($rem));
            if (0 === $ret) {
                return true;
            }
            if (self::EINTR === self::errno($ffi)) {
                $req->tv_sec = $rem->tv_sec;
                $req->tv_nsec = $rem->tv_nsec;

                continue;
            }

            return false;
        }
    }

    private static function errno(\FFI $ffi): int
    {
        $loc = $ffi->__errno_location();

        return (int) $loc[0];
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
        if (!self::ffiEnabled()) {
            return null;
        }
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\extension_loaded('ffi')) {
            self::$ffiUnavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
typedef unsigned int useconds_t;
typedef long time_t;

struct timespec {
    time_t tv_sec;
    long tv_nsec;
};

struct timeval {
    time_t tv_sec;
    long tv_usec;
};

unsigned int sleep(unsigned int seconds);
int usleep(useconds_t usec);
int nanosleep(const struct timespec *req, struct timespec *rem);
int gettimeofday(struct timeval *tv, void *tz);
int *__errno_location(void);
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
