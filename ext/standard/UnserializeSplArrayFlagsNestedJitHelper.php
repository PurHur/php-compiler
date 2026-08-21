<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * NestedJIT: locate `i:0;i:` flags in ArrayObject unserialize bag (#33636).
 *
 * Own TU / single method — keep tiny.
 */
final class UnserializeSplArrayFlagsNestedJitHelper
{
    /**
     * @return int flags value, or 0 when missing
     */
    public static function parseFlags(string $payload): int
    {
        $payload = $payload.'';
        $len = \strlen($payload);
        $pos = 0;
        $guard = 0;
        while ($pos < $len && $guard < 512) {
            ++$guard;
            if ($pos + 5 < $len
                && 'i' === $payload[$pos]
                && ':' === $payload[$pos + 1]
                && '0' === $payload[$pos + 2]
                && ';' === $payload[$pos + 3]
                && 'i' === $payload[$pos + 4]
                && ':' === $payload[$pos + 5]) {
                $j = $pos + 6;
                $flags = 0;
                $neg = false;
                if ($j < $len && '-' === $payload[$j]) {
                    $neg = true;
                    $j = $j + 1;
                }
                $saw = false;
                while ($j < $len && $payload[$j] >= '0' && $payload[$j] <= '9') {
                    $saw = true;
                    $flags = $flags * 10 + (\ord($payload[$j]) - 48);
                    $j = $j + 1;
                }
                if ($saw && $neg) {
                    $flags = 0 - $flags;
                }

                return $flags;
            }
            $pos = $pos + 1;
        }

        return 0;
    }
}
