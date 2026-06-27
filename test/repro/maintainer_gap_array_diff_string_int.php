<?php

declare(strict_types=1);

// Issue #12730 — array_diff() loose compare: string haystack vs int compare array.
$got = array_diff(['1', '2'], [1]);
$expected = [1 => '2'];
if ($got !== $expected) {
    fwrite(STDERR, 'FAIL: expected '.var_export($expected, true).' got '.var_export($got, true)."\n");
    exit(1);
}
echo "ok\n";
