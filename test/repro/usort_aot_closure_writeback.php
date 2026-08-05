<?php

declare(strict_types=1);

/**
 * #26954 — AOT usort() with Closure comparator must reorder the array in place.
 *
 * Zend / VM / JIT: 1,2,3
 * Broken AOT (pre-fix): 3,1,2 (silent wrong output)
 */
$a = [3, 1, 2];
usort($a, static fn ($x, $y) => $x <=> $y);
echo implode(',', $a), "\n";
