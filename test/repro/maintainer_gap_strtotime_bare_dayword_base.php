<?php

declare(strict_types=1);

date_default_timezone_set('UTC');

$base = strtotime('2024-01-15 12:00:00');
if (false === $base) {
    echo "fail: base\n";
    exit(1);
}

$cases = [
    'tomorrow' => strtotime('2024-01-16 00:00:00'),
    'yesterday' => strtotime('2024-01-14 00:00:00'),
    'today' => strtotime('2024-01-15 00:00:00'),
];

foreach ($cases as $phrase => $expected) {
    $got = strtotime($phrase, $base);
    if ($got !== $expected) {
        echo 'fail: ', $phrase, ' got ', var_export($got, true), ' expected ', $expected, "\n";
        exit(1);
    }
}

echo "ok\n";
