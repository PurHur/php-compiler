<?php

declare(strict_types=1);

if (!enum_exists('RoundingMode', false)) {
    echo "skip: RoundingMode not registered\n";
    exit(0);
}

$name = RoundingMode::HalfAwayFromZero->name;
$value = RoundingMode::HalfAwayFromZero->value;

if ('HalfAwayFromZero' !== $name) {
    echo "fail: name expected HalfAwayFromZero, got {$name}\n";
    exit(1);
}

if (!is_int($value) || 1 !== $value) {
    echo 'fail: HalfAwayFromZero->value expected int 1, got ';
    var_export($value);
    echo "\n";
    exit(1);
}

echo "ok\n";
