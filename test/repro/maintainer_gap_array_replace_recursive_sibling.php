<?php

declare(strict_types=1);

// Issue #12729 — array_replace_recursive() must retain sibling nested keys.
$got = array_replace_recursive(['a' => ['b' => 1]], ['a' => ['c' => 2]]);
$expected = ['a' => ['b' => 1, 'c' => 2]];
if ($got !== $expected) {
    fwrite(STDERR, 'FAIL: expected '.var_export($expected, true).' got '.var_export($got, true)."\n");
    exit(1);
}
echo "ok\n";
