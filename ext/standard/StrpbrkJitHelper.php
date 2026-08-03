<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * strpbrk() algorithm peer for unit tests (#14791, #27055).
 *
 * AOT/JIT call sites use length-bounded LLVM in {@see \PHPCompiler\JIT\Builtin\StringStrpbrk}
 * (`phpc_strpbrk_scan`) — NestedJIT helpers mis-materialize nullable {@see __string__*} under
 * thin AOT. VM SSOT remains {@see VmString::strpbrk()}.
 * php-src: ext/standard/string.c — PHP_FUNCTION(strpbrk)
 */
final class StrpbrkJitHelper
{
    /**
     * @return ?string null when strpbrk() would return false
     */
    public static function strpbrkArgv(string $haystack, string $mask): ?string
    {
        $mlen = self::byteLength($mask);
        if (0 === $mlen) {
            throw new \ValueError('strpbrk(): Argument #2 ($characters) must be a non-empty string');
        }
        $slen = self::byteLength($haystack);
        for ($i = 0; $i < $slen; ++$i) {
            $ch = $haystack[$i];
            for ($j = 0; $j < $mlen; ++$j) {
                if ($ch === $mask[$j]) {
                    return self::byteSlice($haystack, $i, $slen - $i);
                }
            }
        }

        return null;
    }

    private static function byteLength(string $s): int
    {
        $n = 0;
        while (isset($s[$n])) {
            ++$n;
        }

        return $n;
    }

    private static function byteSlice(string $s, int $start, int $length): string
    {
        $out = '';
        for ($i = 0; $i < $length; ++$i) {
            $out .= $s[$start + $i];
        }

        return $out;
    }
}
