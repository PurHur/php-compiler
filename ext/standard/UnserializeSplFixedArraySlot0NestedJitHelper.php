<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * NestedJIT: SplFixedArray bag slot 0 `i:0;i:NUM;` (#33640).
 * Own TU / single method — keep tiny.
 *
 * @return int new pos after slot, or -1
 */
final class UnserializeSplFixedArraySlot0NestedJitHelper
{
    public static function fill(int $htPtr, string $payload, int $pos): int
    {
        if ($htPtr <= 0 || $pos < 0) {
            return -1;
        }
        $payload = $payload.'';
        $len = \strlen($payload);
        if ($pos >= $len || 'i' !== $payload[$pos]) {
            return -1;
        }
        $pos = $pos + 1;
        if ($pos >= $len || ':' !== $payload[$pos]) {
            return -1;
        }
        $pos = $pos + 1;
        if ($pos >= $len || '0' !== $payload[$pos]) {
            return -1;
        }
        $pos = $pos + 1;
        if ($pos >= $len || ';' !== $payload[$pos]) {
            return -1;
        }
        $pos = $pos + 1;
        if ($pos >= $len || 'i' !== $payload[$pos]) {
            return -1;
        }
        $pos = $pos + 1;
        if ($pos >= $len || ':' !== $payload[$pos]) {
            return -1;
        }
        $pos = $pos + 1;
        $num = 0;
        $saw = false;
        while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
            $saw = true;
            $num = $num * 10 + (\ord($payload[$pos]) - 48);
            $pos = $pos + 1;
        }
        if (!$saw || $pos >= $len || ';' !== $payload[$pos]) {
            return -1;
        }
        $pos = $pos + 1;
        phpc_native_ht_set_long_at($htPtr, 0, $num);

        return $pos;
    }
}
