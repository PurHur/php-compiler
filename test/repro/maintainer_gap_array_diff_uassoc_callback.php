<?php

declare(strict_types=1);

/**
 * Repro for #13098 — array_diff_uassoc/array_intersect_uassoc value callback.
 *
 * Callback compares values (1 <=> true === 0); keys matched at same index.
 */
$cmp = static fn (mixed $a, mixed $b): int => $a <=> $b;

$diff = array_diff_uassoc([0 => 1], [0 => true], $cmp);
$intersect = array_intersect_uassoc([0 => 1], [0 => true], $cmp);

if ([] !== $diff) {
    fwrite(STDERR, "array_diff_uassoc: expected [], got ".var_export($diff, true)."\n");
    exit(1);
}
if ([0 => 1] !== $intersect) {
    fwrite(STDERR, "array_intersect_uassoc: expected [0=>1], got ".var_export($intersect, true)."\n");
    exit(1);
}

echo "ok\n";
