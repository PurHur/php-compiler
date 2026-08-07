<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

/**
 * Collator::compare() / collator_compare() for compiled JIT/AOT (#28649).
 *
 * NestedJIT-self-contained. Done-when: compare("a","b") → -1 (byte-order /
 * PHP spaceship fallback when ICU handle is unavailable — peer
 * {@see VmCollator::compare}).
 *
 * Avoid VmCollator / ICU FFI under NestedJIT (thin AOT silent-null #579).
 * php-src: ext/intl/collator/collator_compare.c — PHP_FUNCTION(collator_compare)
 */
final class CollatorCompareJitHelper
{
    /**
     * @return int -1 / 0 / 1
     */
    public static function compareUtf8Argv(string $string1, string $string2): int
    {
        if ($string1 === $string2) {
            return 0;
        }
        if ($string1 < $string2) {
            return -1;
        }

        return 1;
    }
}
