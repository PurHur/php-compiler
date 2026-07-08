<?php

declare(strict_types=1);

/**
 * Maintainer repro: Range::from() inclusive intervals (#17427).
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_range_from_84.php
 */

$intParts = [];
foreach (Range::from(1, 3) as $i) {
    $intParts[] = $i;
}
$stringParts = [];
foreach (Range::from('a', 'c') as $c) {
    $stringParts[] = $c;
}

$ok = $intParts === [1, 2, 3]
    && $stringParts === ['a', 'b', 'c']
    && class_exists('Range', false);

echo $ok ? "ok\n" : "fail\n";
