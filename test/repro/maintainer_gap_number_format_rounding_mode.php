<?php

declare(strict_types=1);

if (!enum_exists('RoundingMode', false)) {
    echo "skip: RoundingMode not on reference profile\n";
    exit(0);
}

$halfUp = number_format(2.5, 0, '.', '', RoundingMode::HalfAwayFromZero);
if ('3' !== $halfUp) {
    echo 'fail: HalfAwayFromZero got ', var_export($halfUp, true), " expected '3'\n";
    exit(1);
}

$towardsZero = number_format(2.5, 0, '.', '', RoundingMode::TowardsZero);
if ('2' !== $towardsZero) {
    echo 'fail: TowardsZero got ', var_export($towardsZero, true), " expected '2'\n";
    exit(1);
}

$oneDecimal = number_format(1.55, 1, '.', '', RoundingMode::HalfAwayFromZero);
if ('1.6' !== $oneDecimal) {
    echo 'fail: 1.55/1 decimal got ', var_export($oneDecimal, true), " expected '1.6'\n";
    exit(1);
}

echo "ok\n";
