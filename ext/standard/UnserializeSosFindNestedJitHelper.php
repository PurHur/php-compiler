<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * NestedJIT: detect `i:0;a:` in SOS wire (#33876). Tiny TU.
 * Use `\ord()===48` — NestedJIT breaks on `$s[$i]==='0'`.
 */
final class UnserializeSosFindNestedJitHelper
{
    /** @param mixed $payload */
    public static function findStorage(string $payload): int
    {
        $payload = $payload.'';
        $len = \strlen($payload);
        $pos = 0;
        $guard = 0;
        while ($pos < $len && $guard < 512) {
            ++$guard;
            if ($pos + 5 < $len
                && 105 === \ord($payload[$pos])
                && 58 === \ord($payload[$pos + 1])
                && 48 === \ord($payload[$pos + 2])
                && 59 === \ord($payload[$pos + 3])
                && 97 === \ord($payload[$pos + 4])
                && 58 === \ord($payload[$pos + 5])) {
                return $pos;
            }
            $pos = $pos + 1;
        }

        return -1;
    }
}
