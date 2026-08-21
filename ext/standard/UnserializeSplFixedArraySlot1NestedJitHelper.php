<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * NestedJIT: SplFixedArray bag slot 1 `i:1;N;` (#33640).
 * Own TU / single method — keep tiny.
 *
 * @return int new pos after slot, or -1
 */
final class UnserializeSplFixedArraySlot1NestedJitHelper
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
        if ($pos >= $len || '1' !== $payload[$pos]) {
            return -1;
        }
        $pos = $pos + 1;
        if ($pos >= $len || ';' !== $payload[$pos]) {
            return -1;
        }
        $pos = $pos + 1;
        if ($pos >= $len || 'N' !== $payload[$pos]) {
            return -1;
        }
        $pos = $pos + 1;
        if ($pos >= $len || ';' !== $payload[$pos]) {
            return -1;
        }
        $pos = $pos + 1;
        phpc_native_ht_set_null_at($htPtr, 1);

        return $pos;
    }
}
