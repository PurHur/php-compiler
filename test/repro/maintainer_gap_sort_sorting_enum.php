<?php

declare(strict_types=1);

/**
 * Maintainer repro: sort() with Sorting enum only when registered (#12362).
 *
 * On reference profile both engines must fail before sort() — class Sorting missing.
 */

if (!class_exists('Sorting', false)) {
    echo "ok\n";
    exit(0);
}

$a = [3, 1, 2];
sort($a, Sorting::Ascending);
var_export($a);
echo "\n";

$b = [3, 1, 2];
sort($b, direction: SortDirection::Ascending);
var_export($b);
echo "\n";
