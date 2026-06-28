<?php

declare(strict_types=1);

/**
 * Repro for #13097 — array_diff_assoc/array_intersect_assoc loose value compare.
 *
 * Zend: int/bool pairs match with ==; VM must not use ===.
 */
$diff = array_diff_assoc([0 => 1], [0 => true]);
$intersect = array_intersect_assoc([0 => 1], [0 => true]);

if ([] !== $diff) {
    fwrite(STDERR, "array_diff_assoc: expected [], got ".var_export($diff, true)."\n");
    exit(1);
}
if ([0 => 1] !== $intersect) {
    fwrite(STDERR, "array_intersect_assoc: expected [0=>1], got ".var_export($intersect, true)."\n");
    exit(1);
}

echo "ok\n";
