<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * NestedJIT: SplFixedArray bag slot 2 `i:2;s:LEN:"…";` (#33640).
 * Own TU / single method — keep tiny.
 *
 * @return int 1 on success, 0 on failure
 */
final class UnserializeSplFixedArraySlot2NestedJitHelper
{
    public static function fill(int $htPtr, string $payload, int $pos): int
    {
        if ($htPtr <= 0 || $pos < 0) {
            return 0;
        }
        $payload = $payload.'';
        $len = \strlen($payload);
        if ($pos >= $len || 'i' !== $payload[$pos]) {
            return 0;
        }
        $pos = $pos + 1;
        if ($pos >= $len || ':' !== $payload[$pos]) {
            return 0;
        }
        $pos = $pos + 1;
        if ($pos >= $len || '2' !== $payload[$pos]) {
            return 0;
        }
        $pos = $pos + 1;
        if ($pos >= $len || ';' !== $payload[$pos]) {
            return 0;
        }
        $pos = $pos + 1;
        if ($pos >= $len || 's' !== $payload[$pos]) {
            return 0;
        }
        $pos = $pos + 1;
        if ($pos >= $len || ':' !== $payload[$pos]) {
            return 0;
        }
        $pos = $pos + 1;
        $slen = 0;
        $sawS = false;
        while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
            $sawS = true;
            $slen = $slen * 10 + (\ord($payload[$pos]) - 48);
            $pos = $pos + 1;
        }
        if (!$sawS || $pos >= $len || ':' !== $payload[$pos]) {
            return 0;
        }
        $pos = $pos + 1;
        if ($pos >= $len || '"' !== $payload[$pos]) {
            return 0;
        }
        $pos = $pos + 1;
        $str = '';
        $si = 0;
        while ($si < $slen && $pos < $len) {
            $str .= $payload[$pos];
            $pos = $pos + 1;
            $si = $si + 1;
        }
        if ($pos >= $len || '"' !== $payload[$pos]) {
            return 0;
        }
        $pos = $pos + 1;
        if ($pos >= $len || ';' !== $payload[$pos]) {
            return 0;
        }
        phpc_native_ht_set_string_at($htPtr, 2, $str);

        return 1;
    }
}
