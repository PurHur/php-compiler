--TEST--
stdlib strtotime() — first|second|third|fourth|fifth|last weekday of month (#19550, ext/date/lib/parse_date.re)
--FILE--
<?php
declare(strict_types=1);
date_default_timezone_set('UTC');
$base = strtotime('2020-01-15 12:00:00');
$cases = [
    'second Monday of January 2020' => '2020-01-13',
    'third Friday of January 2020' => '2020-01-17',
    'fourth Sunday of February 2020' => '2020-02-23',
    'fifth Monday of March 2020' => '2020-03-30',
    // timelib overflows past month end when the Nth weekday does not exist
    'fifth Monday of February 2020' => '2020-03-02',
    'first Monday of January 2020' => '2020-01-06',
    'last Monday of January 2020' => '2020-01-27',
    'second Monday of this month' => '2020-01-13',
    'third Friday of last month' => '2019-12-20',
    'first Monday of next month' => '2020-02-03',
    '2020-01-01 third Friday of this month' => '2020-01-17',
    '2020-02-01 fifth Monday of this month' => '2020-03-02',
];
foreach ($cases as $phrase => $expected) {
    $ts = strtotime($phrase, $base);
    if (false === $ts || date('Y-m-d', $ts) !== $expected) {
        echo "fail: {$phrase} => ", false === $ts ? 'false' : date('Y-m-d', $ts), " (want {$expected})\n";
        exit(1);
    }
}
echo "ok\n";
--EXPECT--
ok
