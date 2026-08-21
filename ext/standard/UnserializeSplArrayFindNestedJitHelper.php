<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * NestedJIT: locate `i:1;a:` in ArrayObject unserialize bag (#33636).
 *
 * Own TU / single method — keep tiny (NestedJIT blanks large bodies).
 * Do not use strpos() — NestedJIT returns 0 for non-zero matches.
 */
final class UnserializeSplArrayFindNestedJitHelper
{
    /**
     * @param mixed $payload
     *
     * @return int byte offset of `i:1;a:` or -1
     */
    public static function findStorage(string $payload): int
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
                && '1' === $payload[$pos + 2]
                && ';' === $payload[$pos + 3]
                && 'a' === $payload[$pos + 4]
                && ':' === $payload[$pos + 5]) {
                return $pos;
            }
            $pos = $pos + 1;
        }

        return -1;
    }
}
