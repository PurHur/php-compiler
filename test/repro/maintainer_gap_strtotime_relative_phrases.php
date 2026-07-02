<?php

declare(strict_types=1);

date_default_timezone_set('UTC');

$base = strtotime('2020-01-15 12:00:00');
if (false === $base) {
    echo "fail: base\n";
    exit(1);
}

$cases = [
    'last monday of July 2020' => 1595808000,
    'midnight' => 1579046400,
    'noon' => 1579089600,
    'tomorrow 12:30pm' => 1579177800,
    '+1 week 2 days 4 hours 2 seconds' => 1579881602,
    'next Thursday' => 1579132800,
];

foreach ($cases as $phrase => $expected) {
    $got = strtotime($phrase, $base);
    if ($got !== $expected) {
        echo 'fail: ', $phrase, ' got ', var_export($got, true), ' expected ', $expected, "\n";
        exit(1);
    }
}

echo "ok\n";
