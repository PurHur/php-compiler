<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * NestedJIT: fill SplFixedArray `__spl_ht` from bag body after `{` (#33640).
 *
 * Own TU / single method — mirror ArrayObject fill style (`$pos = $pos + N` peeks).
 * `$pos` is first char after `{`.
 */
final class UnserializeSplFixedArrayFillNestedJitHelper
{
    public static function fillAt(int $htPtr, string $payload, int $pos): int
    {
        if ($htPtr <= 0 || $pos < 0) {
            return 0;
        }
        $payload = $payload.'';
        $len = \strlen($payload);
        $n = 0;
        while ($n < 64 && $pos < $len && '}' !== $payload[$pos]) {
            if ('i' !== $payload[$pos] || $pos + 1 >= $len || ':' !== $payload[$pos + 1]) {
                return 0;
            }
            $pos = $pos + 2;
            $idx = 0;
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
            if ($pos >= $len) {
                return 0;
            }
            if ('N' === $payload[$pos] && $pos + 1 < $len && ';' === $payload[$pos + 1]) {
                $pos = $pos + 2;
                phpc_native_ht_set_null_at($htPtr, $idx);
                ++$n;
                continue;
            }
            if ('i' === $payload[$pos] && $pos + 1 < $len && ':' === $payload[$pos + 1]) {
                $pos = $pos + 2;
                $num = 0;
                $vneg = false;
                if ($pos < $len && '-' === $payload[$pos]) {
                    $vneg = true;
                    $pos = $pos + 1;
                }
                $sawN = false;
                while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
                    $sawN = true;
                    $num = $num * 10 + (\ord($payload[$pos]) - 48);
                    $pos = $pos + 1;
                }
                if (!$sawN || $pos >= $len || ';' !== $payload[$pos]) {
                    return 0;
                }
                $pos = $pos + 1;
                if ($vneg) {
                    $num = 0 - $num;
                }
                phpc_native_ht_set_long_at($htPtr, $idx, $num);
                ++$n;
                continue;
            }
            if ('s' === $payload[$pos] && $pos + 1 < $len && ':' === $payload[$pos + 1]) {
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
                $str = '';
                $si = 0;
                while ($si < $slen && $pos < $len) {
                    $str .= $payload[$pos];
                    $pos = $pos + 1;
                    $si = $si + 1;
                }
                if ($pos + 1 >= $len || '"' !== $payload[$pos] || ';' !== $payload[$pos + 1]) {
                    return 0;
                }
                $pos = $pos + 2;
                phpc_native_ht_set_string_at($htPtr, $idx, $str);
                ++$n;
                continue;
            }

            return 0;
        }

        return 1;
    }
}
