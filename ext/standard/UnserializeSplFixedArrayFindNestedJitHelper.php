<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * NestedJIT: locate prop-count + `{` in SplFixedArray O: wire (#33640).
 *
 * Own TU / single method — keep tiny.
 * Returns byte offset of the first char after `{`, or -1.
 */
final class UnserializeSplFixedArrayFindNestedJitHelper
{
    public static function afterBrace(string $payload): int
    {
        $payload = $payload.'';
        $len = \strlen($payload);
        $pos = 0;
        $guard = 0;
        while ($pos < $len && $guard < 512) {
            ++$guard;
            if ($pos + 2 < $len
                && '"' === $payload[$pos]
                && ':' === $payload[$pos + 1]) {
                $j = $pos + 2;
                $saw = false;
                while ($j < $len && $payload[$j] >= '0' && $payload[$j] <= '9') {
                    $saw = true;
                    $j = $j + 1;
                }
                if ($saw && $j + 1 < $len && ':' === $payload[$j] && '{' === $payload[$j + 1]) {
                    return $j + 2;
                }
            }
            $pos = $pos + 1;
        }

        return -1;
    }
}
