<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * NestedJIT legacy ArrayObject::unserialize — int-key `x:i:flags;a:N:{…};m:…` (#35111).
 *
 * Own TU / single small method. Returns flags or -1.
 */
final class UnserializeSplArrayLegacyNestedJitHelper
{
    public static function restore(int $htPtr, string $payload): int
    {
        if ($htPtr <= 0) {
            return -1;
        }
        $payload = $payload.'';
        $len = \strlen($payload);
        if ($len < 8 || 'x' !== $payload[0] || ':' !== $payload[1] || 'i' !== $payload[2]) {
            return -1;
        }
        $pos = 4;
        $flags = 0;
        $saw = false;
        while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
            $saw = true;
            $flags = $flags * 10 + (\ord($payload[$pos]) - 48);
            $pos = $pos + 1;
        }
        if (!$saw || $pos >= $len || ';' !== $payload[$pos]) {
            return -1;
        }
        $pos = $pos + 1;
        if ($pos + 1 >= $len || 'a' !== $payload[$pos] || ':' !== $payload[$pos + 1]) {
            return -1;
        }
        $pos = $pos + 2;
        $count = 0;
        $sawC = false;
        while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
            $sawC = true;
            $count = $count * 10 + (\ord($payload[$pos]) - 48);
            $pos = $pos + 1;
        }
        if (!$sawC || $pos + 1 >= $len || ':' !== $payload[$pos] || '{' !== $payload[$pos + 1]) {
            return -1;
        }
        $pos = $pos + 2;
        $n = 0;
        while ($n < $count && $n < 64 && $pos < $len) {
            if ('i' !== $payload[$pos] || $pos + 1 >= $len || ':' !== $payload[$pos + 1]) {
                return -1;
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
                return -1;
            }
            $pos = $pos + 1;
            if ($pos >= $len) {
                return -1;
            }
            if ('i' === $payload[$pos] && $pos + 1 < $len && ':' === $payload[$pos + 1]) {
                $pos = $pos + 2;
                $num = 0;
                $sawN = false;
                while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
                    $sawN = true;
                    $num = $num * 10 + (\ord($payload[$pos]) - 48);
                    $pos = $pos + 1;
                }
                if (!$sawN || $pos >= $len || ';' !== $payload[$pos]) {
                    return -1;
                }
                $pos = $pos + 1;
                phpc_native_ht_set_long_at($htPtr, $idx, $num);
            } elseif ('s' === $payload[$pos] && $pos + 1 < $len && ':' === $payload[$pos + 1]) {
                $pos = $pos + 2;
                $slen = 0;
                $sawL = false;
                while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
                    $sawL = true;
                    $slen = $slen * 10 + (\ord($payload[$pos]) - 48);
                    $pos = $pos + 1;
                }
                if (!$sawL || $pos + 1 >= $len || ':' !== $payload[$pos] || '"' !== $payload[$pos + 1]) {
                    return -1;
                }
                $pos = $pos + 2;
                if ($pos + $slen + 1 >= $len) {
                    return -1;
                }
                $s = \substr($payload, $pos, $slen);
                phpc_native_ht_set_string_at($htPtr, $idx, $s);
                $pos = $pos + $slen + 2;
            } elseif ('N' === $payload[$pos] && $pos + 1 < $len && ';' === $payload[$pos + 1]) {
                phpc_native_ht_set_null_at($htPtr, $idx);
                $pos = $pos + 2;
            } else {
                return -1;
            }
            $n = $n + 1;
        }

        return $flags;
    }
}
