<?php
declare(strict_types=1);
date_default_timezone_set('UTC');
$base = strtotime('2020-01-15 12:00:00');
$cases = [
    'second Monday of January 2020' => '2020-01-13',
    'third Friday of January 2020' => '2020-01-17',
    'fifth Monday of February 2020' => '2020-03-02',
    'second Monday of this month' => '2020-01-13',
    '2020-01-01 third Friday of this month' => '2020-01-17',
];
$ok = true;
foreach ($cases as $phrase => $expected) {
    $ts = strtotime($phrase, $base);
    $got = false === $ts ? 'false' : date('Y-m-d', $ts);
    echo $phrase, ' => ', $got, $got === $expected ? " OK\n" : " FAIL want {$expected}\n";
    if ($got !== $expected) {
        $ok = false;
    }
}
exit($ok ? 0 : 1);
