<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Repro;

/**
 * Repro for #20080 — ucfirst/lcfirst/ucwords/str_shuffle/str_repeat(null)
 * must TypeError under PROFILE=8.4 (php-src ext/standard/string.c Z_PARAM_STR).
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/ucfirst_null_forward84_20080.php
 */

$pass = 0;
$fail = 0;

foreach (['ucfirst', 'lcfirst', 'ucwords', 'str_shuffle'] as $fn) {
    try {
        $fn(null);
        echo "FAIL: $fn(null) did not throw\n";
        ++$fail;
    } catch (\TypeError $e) {
        echo "PASS: $fn(null) -> TypeError\n";
        ++$pass;
    }
}

try {
    str_repeat(null, 1);
    echo "FAIL: str_repeat(null, 1) did not throw\n";
    ++$fail;
} catch (\TypeError $e) {
    echo "PASS: str_repeat(null, 1) -> TypeError\n";
    ++$pass;
}

echo "\n$pass passed, $fail failed\n";
if ($fail > 0) {
    exit(1);
}
