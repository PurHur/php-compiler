<?php

declare(strict_types=1);

if (!enum_exists('RoundingMode', false)) {
    echo 'fail: enum_exists(RoundingMode) false on ' . PHP_VERSION . "\n";
    exit(1);
}

$result = round(2.5, 0, RoundingMode::HalfAwayFromZero);
if (3.0 !== $result) {
    echo "fail: round with RoundingMode returned {$result}\n";
    exit(1);
}

echo "ok\n";
