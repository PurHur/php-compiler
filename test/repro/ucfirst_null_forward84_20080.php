<?php

/**
 * Repro for #24598 / #21428 — ucfirst/lcfirst/ucwords/str_shuffle/str_repeat(null)
 * coerce to '' under PROFILE=8.4 (reverts wrong-direction #24213 / #20080 TypeError).
 * No declare(strict_types=1) — under strict_types Zend also TypeErrors.
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/ucfirst_null_forward84_20080.php
 */

$pass = 0;
$fail = 0;

foreach (['ucfirst', 'lcfirst', 'ucwords', 'str_shuffle'] as $fn) {
    try {
        $r = $fn(null);
        if ('' === $r) {
            echo "PASS: $fn(null) -> ''\n";
            ++$pass;
        } else {
            echo "FAIL: $fn(null) -> ".var_export($r, true)."\n";
            ++$fail;
        }
    } catch (\TypeError $e) {
        echo "FAIL: $fn(null) -> TypeError\n";
        ++$fail;
    }
}

try {
    $r = str_repeat(null, 1);
    if ('' === $r) {
        echo "PASS: str_repeat(null, 1) -> ''\n";
        ++$pass;
    } else {
        echo "FAIL: str_repeat(null, 1) -> ".var_export($r, true)."\n";
        ++$fail;
    }
} catch (\TypeError $e) {
    echo "FAIL: str_repeat(null, 1) -> TypeError\n";
    ++$fail;
}

echo "\n$pass passed, $fail failed\n";
if ($fail > 0) {
    exit(1);
}
