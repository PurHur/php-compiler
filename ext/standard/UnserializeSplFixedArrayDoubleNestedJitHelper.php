<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * NestedJIT: write SplFixedArray `d:` slots (#33673).
 *
 * Own TU — peer of {@see UnserializeSplFixedArrayNestedJitHelper} which skips `d:`.
 */
final class UnserializeSplFixedArrayDoubleNestedJitHelper
{
    public static function restoreInto(int $htPtr, string $payload): int
    {
        if ($htPtr <= 0) {
            return 0;
        }
        $payload = $payload.'';
        $len = \strlen($payload);
        if ($len < 5 || 'O' !== $payload[0] || ':' !== $payload[1]) {
            return 0;
        }
        $pos = 2;
        while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
            $pos = $pos + 1;
        }
        if ($pos + 1 >= $len || ':' !== $payload[$pos] || '"' !== $payload[$pos + 1]) {
            return 0;
        }
        $pos = $pos + 2;
        while ($pos < $len && '"' !== $payload[$pos]) {
            $pos = $pos + 1;
        }
        if ($pos >= $len || '"' !== $payload[$pos]) {
            return 0;
        }
        $pos = $pos + 1;
        if ($pos >= $len || ':' !== $payload[$pos]) {
            return 0;
        }
        $pos = $pos + 1;
        $count = 0;
        $saw = false;
        while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
            $saw = true;
            $count = $count * 10 + (\ord($payload[$pos]) - 48);
            $pos = $pos + 1;
        }
        if (!$saw || $pos + 1 >= $len || ':' !== $payload[$pos] || '{' !== $payload[$pos + 1]) {
            return 0;
        }
        $pos = $pos + 2;
        $n = 0;
        while ($n < $count && $n < 256 && $pos < $len) {
            if ('i' !== $payload[$pos] || $pos + 1 >= $len || ':' !== $payload[$pos + 1]) {
                return 0;
            }
            $pos = $pos + 2;
            $idx = 0;
            $ineg = false;
            if ($pos < $len && '-' === $payload[$pos]) {
                $ineg = true;
                $pos = $pos + 1;
            }
            $sawI = false;
            while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
                $sawI = true;
                $idx = $idx * 10 + (\ord($payload[$pos]) - 48);
                $pos = $pos + 1;
            }
            if (!$sawI || $pos >= $len || ';' !== $payload[$pos]) {
                return 0;
            }
            $pos = $pos + 1;
            if ($ineg) {
                $idx = 0 - $idx;
            }
            if ($idx < 0 || $pos >= $len) {
                return 0;
            }
            if ('d' === $payload[$pos] && $pos + 1 < $len && ':' === $payload[$pos + 1]) {
                $pos = $pos + 2;
                $dstr = '';
                if ($pos < $len && ('-' === $payload[$pos] || '+' === $payload[$pos])) {
                    $dstr .= $payload[$pos];
                    $pos = $pos + 1;
                }
                $sawD = false;
                while ($pos < $len && (($payload[$pos] >= '0' && $payload[$pos] <= '9') || '.' === $payload[$pos])) {
                    $sawD = true;
                    $dstr .= $payload[$pos];
                    $pos = $pos + 1;
                }
                if (!$sawD || $pos >= $len || ';' !== $payload[$pos]) {
                    return 0;
                }
                $pos = $pos + 1;
                phpc_native_ht_set_double_at($htPtr, $idx, $dstr);
            } elseif ('N' === $payload[$pos] && $pos + 1 < $len && ';' === $payload[$pos + 1]) {
                $pos = $pos + 2;
            } elseif ('i' === $payload[$pos] && $pos + 1 < $len && ':' === $payload[$pos + 1]) {
                $pos = $pos + 2;
                if ($pos < $len && '-' === $payload[$pos]) {
                    $pos = $pos + 1;
                }
                while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
                    $pos = $pos + 1;
                }
                if ($pos >= $len || ';' !== $payload[$pos]) {
                    return 0;
                }
                $pos = $pos + 1;
            } elseif ('s' === $payload[$pos] && $pos + 1 < $len && ':' === $payload[$pos + 1]) {
                $pos = $pos + 2;
                $slen = 0;
                while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
                    $slen = $slen * 10 + (\ord($payload[$pos]) - 48);
                    $pos = $pos + 1;
                }
                if ($pos + 1 >= $len || ':' !== $payload[$pos] || '"' !== $payload[$pos + 1]) {
                    return 0;
                }
                $pos = $pos + 2;
                $si = 0;
                while ($si < $slen && $pos < $len) {
                    $pos = $pos + 1;
                    $si = $si + 1;
                }
                if ($pos + 1 >= $len || '"' !== $payload[$pos] || ';' !== $payload[$pos + 1]) {
                    return 0;
                }
                $pos = $pos + 2;
            } elseif ('b' === $payload[$pos] && $pos + 3 < $len && ':' === $payload[$pos + 1]
                && ('0' === $payload[$pos + 2] || '1' === $payload[$pos + 2])
                && ';' === $payload[$pos + 3]) {
                $pos = $pos + 4;
            } else {
                return 0;
            }
            $n = $n + 1;
        }

        return 1;
    }
}
