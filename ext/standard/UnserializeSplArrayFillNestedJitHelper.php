<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * NestedJIT: fill `__spl_ht` from bag storage at `i:1;a:` offset (#33636 / #33663 / #33670).
 *
 * Own TU / single method — string-key bags with int / string / float / bool / null values.
 * `$pos` must point at the `i` of `i:1;a:`.
 * Packed int-key bags stay in {@see UnserializeSplArrayFillIntKeyNestedJitHelper}.
 */
final class UnserializeSplArrayFillNestedJitHelper
{
    public static function fillAt(int $htPtr, string $payload, int $pos): int
    {
        if ($htPtr <= 0 || $pos < 0) {
            return 0;
        }
        $payload = $payload.'';
        $len = \strlen($payload);
        // Skip i:1;a:
        $pos = $pos + 6;
        $count = 0;
        $saw = false;
        $cg = 0;
        while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9' && $cg < 20) {
            ++$cg;
            $saw = true;
            $count = $count * 10 + (\ord($payload[$pos]) - 48);
            $pos = $pos + 1;
        }
        if (!$saw || $pos + 1 >= $len || ':' !== $payload[$pos] || '{' !== $payload[$pos + 1]) {
            return 0;
        }
        $pos = $pos + 2;
        $n = 0;
        while ($n < $count && $n < 64 && $pos < $len) {
            if ('s' !== $payload[$pos] || $pos + 1 >= $len || ':' !== $payload[$pos + 1]) {
                return 0;
            }
            $pos = $pos + 2;
            $klen = 0;
            while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
                $klen = $klen * 10 + (\ord($payload[$pos]) - 48);
                $pos = $pos + 1;
            }
            if ($pos + 1 >= $len || ':' !== $payload[$pos] || '"' !== $payload[$pos + 1]) {
                return 0;
            }
            $pos = $pos + 2;
            $key = '';
            $ki = 0;
            while ($ki < $klen && $pos < $len) {
                $key .= $payload[$pos];
                $pos = $pos + 1;
                $ki = $ki + 1;
            }
            if ($pos + 1 >= $len || '"' !== $payload[$pos] || ';' !== $payload[$pos + 1]) {
                return 0;
            }
            $pos = $pos + 2;
            if ('N' === $payload[$pos] && $pos + 1 < $len && ';' === $payload[$pos + 1]) {
                phpc_native_ht_set_string_key_null($htPtr, $key);
                $pos = $pos + 2;
            } elseif ('i' === $payload[$pos] && $pos + 1 < $len && ':' === $payload[$pos + 1]) {
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
                phpc_native_ht_set_string_key_long($htPtr, $key, $num);
            } elseif ('s' === $payload[$pos] && $pos + 1 < $len && ':' === $payload[$pos + 1]) {
                $pos = $pos + 2;
                $slen = 0;
                $sawS = false;
                while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
                    $sawS = true;
                    $slen = $slen * 10 + (\ord($payload[$pos]) - 48);
                    $pos = $pos + 1;
                }
                if (!$sawS || $pos + 1 >= $len || ':' !== $payload[$pos] || '"' !== $payload[$pos + 1]) {
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
                phpc_native_ht_set_string_key($htPtr, $key, $str);
            } elseif ('b' === $payload[$pos] && $pos + 3 < $len && ':' === $payload[$pos + 1]
                && ('0' === $payload[$pos + 2] || '1' === $payload[$pos + 2])
                && ';' === $payload[$pos + 3]) {
                $bval = 0;
                if ('1' === $payload[$pos + 2]) {
                    $bval = 1;
                }
                phpc_native_ht_set_string_key_bool($htPtr, $key, $bval);
                $pos = $pos + 4;
            } elseif ('d' === $payload[$pos] && $pos + 1 < $len && ':' === $payload[$pos + 1]) {
                $pos = $pos + 2;
                $fstr = '';
                while ($pos < $len && ';' !== $payload[$pos]) {
                    $fstr .= $payload[$pos];
                    $pos = $pos + 1;
                }
                if ($pos >= $len || ';' !== $payload[$pos] || '' === $fstr) {
                    return 0;
                }
                $pos = $pos + 1;
                phpc_native_ht_set_string_key_double_str($htPtr, $key, $fstr);
            } else {
                return 0;
            }
            ++$n;
        }

        return 1;
    }
}
