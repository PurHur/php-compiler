<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * NestedJIT legacy Serializable::unserialize() for SplDoublyLinkedList family (#35111).
 *
 * Own TU / single method. Wire: `i:flags;:elem;:elem;…` (php-src zim_SplDoublyLinkedList_unserialize).
 * Fills packed `__spl_ht` via phpc_native_ht_set_*_at; returns flags (or -1 on failure).
 */
final class UnserializeSplDllistLegacyNestedJitHelper
{
    public static function restore(int $htPtr, string $payload): int
    {
        if ($htPtr <= 0) {
            return -1;
        }
        $payload = $payload.'';
        $len = \strlen($payload);
        if (0 === $len) {
            return 0;
        }
        $pos = 0;
        if ('i' !== $payload[$pos] || $pos + 1 >= $len || ':' !== $payload[$pos + 1]) {
            return -1;
        }
        $pos = $pos + 2;
        $flags = 0;
        $neg = false;
        if ($pos < $len && '-' === $payload[$pos]) {
            $neg = true;
            $pos = $pos + 1;
        }
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
        if ($neg) {
            $flags = 0 - $flags;
        }
        $idx = 0;
        while ($pos < $len && $idx < 256) {
            if (':' !== $payload[$pos]) {
                return -1;
            }
            $pos = $pos + 1;
            if ($pos >= $len) {
                return -1;
            }
            if ('N' === $payload[$pos] && $pos + 1 < $len && ';' === $payload[$pos + 1]) {
                phpc_native_ht_set_null_at($htPtr, $idx);
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
                    return -1;
                }
                $pos = $pos + 1;
                if ($vneg) {
                    $num = 0 - $num;
                }
                phpc_native_ht_set_long_at($htPtr, $idx, $num);
            } elseif ('b' === $payload[$pos] && $pos + 3 < $len && ':' === $payload[$pos + 1]
                && (';0' === $payload[$pos + 2] || '1' === $payload[$pos + 2])
                && ';' === $payload[$pos + 3]
            ) {
                phpc_native_ht_set_bool_at($htPtr, $idx, '1' === $payload[$pos + 2] ? 1 : 0);
                $pos = $pos + 4;
            } elseif ('d' === $payload[$pos] && $pos + 1 < $len && ':' === $payload[$pos + 1]) {
                $pos = $pos + 2;
                $start = $pos;
                while ($pos < $len && ';' !== $payload[$pos]) {
                    $pos = $pos + 1;
                }
                if ($pos >= $len || ';' !== $payload[$pos]) {
                    return -1;
                }
                $ds = \substr($payload, $start, $pos - $start);
                phpc_native_ht_set_double_at($htPtr, $idx, (float) $ds);
                $pos = $pos + 1;
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
                if ($pos + $slen + 1 >= $len || '"' !== $payload[$pos + $slen]
                    || ';' !== $payload[$pos + $slen + 1]
                ) {
                    return -1;
                }
                $s = \substr($payload, $pos, $slen);
                phpc_native_ht_set_string_at($htPtr, $idx, $s);
                $pos = $pos + $slen + 2;
            } else {
                return -1;
            }
            $idx = $idx + 1;
        }
        if ($pos !== $len) {
            return -1;
        }

        return $flags;
    }
}
