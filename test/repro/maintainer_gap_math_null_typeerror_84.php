<?php

declare(strict_types=1);

// #18924 — abs()/round()/ceil()/floor(null) TypeError on 8.4 forward profile.
// Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_math_null_typeerror_84.php

$checks = [
    ['abs', [null]],
    ['round', [null]],
    ['ceil', [null]],
    ['floor', [null]],
];

foreach ($checks as [$fn, $args]) {
    try {
        $fn(...$args);
        echo "fail: {$fn}(null) expected TypeError\n";
        exit(1);
    } catch (TypeError $e) {
        if (!str_contains($e->getMessage(), 'must be of type int|float')) {
            echo "fail: {$fn}(null): ", $e->getMessage(), "\n";
            exit(1);
        }
    }
}

echo "ok\n";
