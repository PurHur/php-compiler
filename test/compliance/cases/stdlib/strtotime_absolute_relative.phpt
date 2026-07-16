--TEST--
stdlib strtotime()/date_create() — absolute date + relative phrase (#19534, ext/date/lib/parse_date.re)
--FILE--
<?php
declare(strict_types=1);
date_default_timezone_set('UTC');
$base = strtotime('2020-01-15 00:00:00 UTC');
$cases = [
    '2020-01-15 next Monday' => 1579478400,
    '2020-02-01 last day of this month' => 1582934400,
    '2020-02-01 first day of this month' => 1580515200,
    '2020-01-15 next Friday' => 1579219200,
    '2020-01-15 last Monday' => 1578873600,
    '2020-01-15 next month' => 1581724800,
    '2020-01-15 next year' => 1610668800,
    '15 January 2020 next Monday' => 1579478400,
    '2020-01-15 +1 week 2 days' => 1579824000,
];
foreach ($cases as $phrase => $expected) {
    $got = strtotime($phrase, $base);
    if ($got !== $expected) {
        echo "strtotime fail: {$phrase} got ", var_export($got, true), " expected {$expected}\n";
        exit(1);
    }
}
$d = date_create('2020-01-15 next Monday');
if ($d === false || $d->format('Y-m-d') !== '2020-01-20') {
    echo "date_create fail\n";
    exit(1);
}
$dt = new DateTime('2020-02-01 last day of this month');
if ($dt->format('Y-m-d') !== '2020-02-29') {
    echo "DateTime fail\n";
    exit(1);
}
echo "ok\n";
--EXPECT--
ok
