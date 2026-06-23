<?php

declare(strict_types=1);

/**
 * Issue #10776 — min()/max() NaN operand ordering (php-src ext/standard/array.c).
 *
 * Verify:
 *   php test/repro/maintainer_gap_min_max_nan.php
 *   php bin/vm.php test/repro/maintainer_gap_min_max_nan.php
 *   php bin/compile.php -l test/repro/maintainer_gap_min_max_nan.php && ./test/repro/maintainer_gap_min_max_nan
 */
$n = NAN;
$fail = 0;

if (min(1, $n) !== 1) {
    echo "min(1, NAN): expected 1\n";
    ++$fail;
}
if (!is_nan(max(1, $n))) {
    echo "max(1, NAN): expected NAN\n";
    ++$fail;
}
if (min([1, $n, 3]) !== 3) {
    echo "min([1, NAN, 3]): expected 3\n";
    ++$fail;
}
if (max([1, $n, 3]) !== 3) {
    echo "max([1, NAN, 3]): expected 3\n";
    ++$fail;
}

if (0 === $fail) {
    echo "PASS\n";
    exit(0);
}

echo "FAIL ($fail)\n";
exit(255);
