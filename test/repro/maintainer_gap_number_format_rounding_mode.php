<?php

declare(strict_types=1);

if (!enum_exists('RoundingMode')) {
    fwrite(STDERR, "skip: RoundingMode not on reference profile\n");
    exit(0);
}

$got = number_format(1.55, 1, '.', '', RoundingMode::HalfAwayFromZero);
if ('1.6' !== $got) {
    echo 'fail: got ', var_export($got, true), "\n";
    exit(1);
}

$gotEven = number_format(2.5, 0, '.', '', RoundingMode::HalfEven);
if ('2' !== $gotEven) {
    echo 'fail even: got ', var_export($gotEven, true), "\n";
    exit(1);
}

echo "ok\n";
