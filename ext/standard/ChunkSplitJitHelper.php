<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * chunk_split() for compiled JIT/AOT modules (#14626, #26992, php-in-PHP).
 *
 * Self-contained (no call into VmString) so NestedJIT / helper-runtime units are not
 * ExternalMethod-stubbed (#16075 / peer WordwrapJitHelper #26904 / StrRot13JitHelper #26868).
 * Thin AOT of the prior VmString-chunkSplit delegate segfaulted after c:main_before_php (#26992).
 *
 * Logic mirrors VmString chunkSplit() / php-src ext/standard/string.c PHP_FUNCTION(chunk_split).
 * NestedJIT: string concat only; isset length; private helpers OK.
 */
final class ChunkSplitJitHelper
{
    public static function chunkSplitArgv(string $string, int $length, string $separator): string
    {
        if ($length < 1) {
            throw new \ValueError('chunk_split(): Argument #2 ($length) must be greater than 0');
        }
        $byteLen = self::byteLength($string);
        if (0 === $byteLen) {
            return $separator;
        }
        $out = '';
        for ($i = 0; $i < $byteLen; $i += $length) {
            $chunkLen = $length;
            if ($i + $chunkLen > $byteLen) {
                $chunkLen = $byteLen - $i;
            }
            $out .= self::byteSlice($string, $i, $chunkLen);
            $out .= $separator;
        }

        return $out;
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
