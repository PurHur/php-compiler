<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * NestedJIT: parse trailing `i:1;i:N;` info long from SOS wire (#33876). Tiny TU.
 */
final class UnserializeSosParseInfoNestedJitHelper
{
    /** @param mixed $payload */
    public static function parseInfo(string $payload): int
    {
        $payload = $payload.'';
        $len = \strlen($payload);
        $pos = 0;
        $guard = 0;
        // Prefer the last `i:1;i:` after storage (avoid the earlier i:1 of pair index).
        $last = -1;
        while ($pos + 5 < $len && $guard < 512) {
            ++$guard;
            if (105 === \ord($payload[$pos])
                && 58 === \ord($payload[$pos + 1])
                && 49 === \ord($payload[$pos + 2])
                && 59 === \ord($payload[$pos + 3])
                && 105 === \ord($payload[$pos + 4])
                && 58 === \ord($payload[$pos + 5])) {
                $last = $pos;
            }
            $pos = $pos + 1;
        }
        if ($last < 0) {
            return 0;
        }
        $pos = $last + 6;
        $neg = 0;
        if ($pos < $len && 45 === \ord($payload[$pos])) {
            $neg = 1;
            $pos = $pos + 1;
        }
        $num = 0;
        $saw = 0;
        $dg = 0;
        while ($pos < $len && $dg < 20) {
            $ch = \ord($payload[$pos]);
            if ($ch < 48 || $ch > 57) {
                break;
            }
            $dg = $dg + 1;
            $saw = 1;
            $num = $num * 10 + ($ch - 48);
            $pos = $pos + 1;
        }
        if (0 === $saw) {
            return 0;
        }
        if (1 === $neg) {
            $num = 0 - $num;
        }

        return $num;
    }
}
