<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * uniqid() format for compiled JIT/AOT modules (#14897, #26931, php-in-PHP).
 *
 * Pure NestedJIT (no host time()/hrtime()/random_bytes) so helper TUs stay
 * ExternalMethod-free and compile under HELPER_RUNTIME_O=0 (#16075 / peer
 * StrRot13 #26868). Wall clock + entropy ints come from the LLVM bridge
 * {@see \PHPCompiler\JIT\Builtin\StringUniqid}.
 *
 * NestedJIT traps (whole-file NestedJIT of this unit):
 * - No VmString/VmDate calls (ExternalMethod stub → segfault).
 * - No host builtins in any method (time/random_bytes → type mismatch / OOM).
 * - No `(int)($n / 10)` — assign type mismatch 1/132; use bare `$n = $n / 10`.
 *
 * php-src: ext/standard/uniqid.c — PHP_FUNCTION(uniqid)
 */
final class UniqidJitHelper
{
    /**
     * @param int $sec         tv_sec
     * @param int $usec        tv_usec % 0x100000
     * @param int $moreEntropy 0/1
     * @param int $entropyU32  uint32 seed bytes when more_entropy (ignored otherwise)
     */
    public static function formatArgv(
        string $prefix,
        int $sec,
        int $usec,
        int $moreEntropy,
        int $entropyU32
    ): string {
        $hex = '0123456789abcdef';
        $core = '';
        for ($i = 7; $i >= 0; --$i) {
            $core .= $hex[($sec >> ($i * 4)) & 0xF];
        }
        for ($i = 4; $i >= 0; --$i) {
            $core .= $hex[($usec >> ($i * 4)) & 0xF];
        }
        if (0 !== $moreEntropy) {
            // %.8F-shaped suffix — NestedJIT-safe digit peel (only %10 and /10, no int cast).
            $digits = '0123456789';
            $n = $entropyU32;
            if ($n < 0) {
                $n = -$n;
            }
            $d0 = $n % 10;
            $n = $n / 10;
            $d1 = $n % 10;
            $n = $n / 10;
            $d2 = $n % 10;
            $n = $n / 10;
            $d3 = $n % 10;
            $n = $n / 10;
            $d4 = $n % 10;
            $n = $n / 10;
            $d5 = $n % 10;
            $n = $n / 10;
            $d6 = $n % 10;
            $n = $n / 10;
            $d7 = $n % 10;
            $n = $n / 10;
            $d8 = $n % 10;
            $core .= $digits[$d8 % 10];
            $core .= '.';
            $core .= $digits[$d7 % 10];
            $core .= $digits[$d6 % 10];
            $core .= $digits[$d5 % 10];
            $core .= $digits[$d4 % 10];
            $core .= $digits[$d3 % 10];
            $core .= $digits[$d2 % 10];
            $core .= $digits[$d1 % 10];
            $core .= $digits[$d0 % 10];
        }

        return $prefix.$core;
    }

    /**
     * Host/unit-test entry — pure format with fixed clock (no host builtins; NestedJIT-safe).
     * VM SSOT remains VmString::uniqid.
     */
    public static function uniqidArgv(string $prefix, int $moreEntropy): string
    {
        return self::formatArgv($prefix, 0x6a6f38e3, 0xa8855, $moreEntropy, 0x40000000);
    }
}
