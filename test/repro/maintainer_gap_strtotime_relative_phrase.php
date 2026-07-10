<?php

declare(strict_types=1);

date_default_timezone_set('UTC');

$ts = strtotime('last year January 1');
if (false === $ts) {
    echo "fail: strtotime('last year January 1') returned false\n";
    exit(1);
}

$year = date('Y', $ts);
$expectedYear = (string) ((int) date('Y') - 1);
if ($year !== $expectedYear) {
    echo "fail: date('Y', strtotime('last year January 1')) got {$year} expected {$expectedYear}\n";
    exit(1);
}

$base = strtotime('2020-01-15 12:00:00');
if (false === $base) {
    echo "fail: base\n";
    exit(1);
}

$cases = [
    'last year January 1' => 1546300800,
    'next year January 1' => 1609459200,
    'this year January 1' => 1577836800,
    'January 1 last year' => 1546300800,
    '15 March last year' => 1552608000,
    'last year March 15' => 1552608000,
    'last year' => 1547553600,
    'next year' => 1610712000,
];

foreach ($cases as $phrase => $expected) {
    $got = strtotime($phrase, $base);
    if ($got !== $expected) {
        echo 'fail: ', $phrase, ' got ', var_export($got, true), ' expected ', $expected, "\n";
        exit(1);
    }
}

echo "ok\n";
