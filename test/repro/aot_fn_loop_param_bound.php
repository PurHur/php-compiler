<?php
declare(strict_types=1);
/**
 * #36018 — AOT for/while with function param loop bound must terminate.
 * Stale KIND_VALUE i64 literal on `$i` made `$i < $len` always `$0 < N`.
 */
function names(string $n, int $len): string
{
    $out = [];
    for ($i = 0; $i < $len; $i++) {
        $out[] = (string) $i;
    }

    return $len.'['.implode(',', $out).']';
}

echo names('x', 3), "\n";
