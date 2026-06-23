<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * libc getrusage(2) for VM without host PHP \\getrusage() (#5388 VM phase, #7287 pattern).
 *
 * php-src: ext/standard/microtime.c — PHP_FUNCTION(getrusage)
 * JIT/AOT: GetrusageJitHelper via StringGetrusageRuntime (#9184).
 */
final class VmGetrusageNative
{
    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    /** php-src: if (pwho == 1) who = RUSAGE_CHILDREN; */
    public static function normalizeWho(int $who): int
    {
        if (1 === $who) {
            return -1;
        }

        return $who;
    }

    /**
     * @return array<int|string, int>|false
     */
    public static function getrusage(int $who = 0): array|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        try {
            $libcWho = self::normalizeWho($who);
            $ru = $ffi->new('struct rusage');
            if (0 !== (int) $ffi->getrusage($libcWho, \FFI::addr($ru))) {
                return false;
            }

            return self::toPhpArray($ru);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<int|string, int>
     */
    private static function toPhpArray(\FFI\CData $ru): array
    {
        $fields = [
            'ru_utime.tv_sec' => (int) $ru->ru_utime->tv_sec,
            'ru_utime.tv_usec' => (int) $ru->ru_utime->tv_usec,
            'ru_stime.tv_sec' => (int) $ru->ru_stime->tv_sec,
            'ru_stime.tv_usec' => (int) $ru->ru_stime->tv_usec,
            'ru_maxrss' => (int) $ru->ru_maxrss,
            'ru_ixrss' => (int) $ru->ru_ixrss,
            'ru_idrss' => (int) $ru->ru_idrss,
            'ru_minflt' => (int) $ru->ru_minflt,
            'ru_majflt' => (int) $ru->ru_majflt,
            'ru_nswap' => (int) $ru->ru_nswap,
            'ru_inblock' => (int) $ru->ru_inblock,
            'ru_oublock' => (int) $ru->ru_oublock,
            'ru_msgsnd' => (int) $ru->ru_msgsnd,
            'ru_msgrcv' => (int) $ru->ru_msgrcv,
            'ru_nsignals' => (int) $ru->ru_nsignals,
            'ru_nvcsw' => (int) $ru->ru_nvcsw,
            'ru_nivcsw' => (int) $ru->ru_nivcsw,
        ];
        $out = [];
        $i = 0;
        foreach ($fields as $key => $value) {
            $out[$key] = $value;
            $out[$i] = $value;
            ++$i;
        }

        return $out;
    }

    private static function ffi(): ?\FFI
    {
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!self::ffiEnabled() || !\extension_loaded('ffi')) {
            self::$ffiUnavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
typedef long time_t;
typedef long suseconds_t;
struct timeval {
    time_t tv_sec;
    suseconds_t tv_usec;
};
struct rusage {
    struct timeval ru_utime;
    struct timeval ru_stime;
    long ru_maxrss;
    long ru_ixrss;
    long ru_idrss;
    long ru_isrss;
    long ru_minflt;
    long ru_majflt;
    long ru_nswap;
    long ru_inblock;
    long ru_oublock;
    long ru_msgsnd;
    long ru_msgrcv;
    long ru_nsignals;
    long ru_nvcsw;
    long ru_nivcsw;
};
int getrusage(int who, struct rusage *usage);
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

    private static function ffiEnabled(): bool
    {
        $v = getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== strtolower($v)) {
            return false;
        }

        return true;
    }
}
