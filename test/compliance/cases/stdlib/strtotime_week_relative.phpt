--TEST--
stdlib strtotime() — next|last|this week (+ absolute prefix) (#19547, ext/date/lib/parse_date.re)
--FILE--
<?php
declare(strict_types=1);
date_default_timezone_set('UTC');
$base = strtotime('2020-01-15 12:00:00');
$cases = [
    'next week' => 1579521600,
    'last week' => 1578312000,
    'this week' => 1578916800,
    '2020-01-15 next week' => 1579478400,
    '2020-01-15 last week' => 1578268800,
    '2020-01-15 this week' => 1578873600,
];
foreach ($cases as $phrase => $expected) {
    $got = strtotime($phrase, $base);
    if ($got !== $expected) {
        echo "fail {$phrase} got ", var_export($got, true), " expected {$expected}\n";
        exit(1);
    }
}
$sun = strtotime('2020-01-12 12:00:00');
if (strtotime('next week', $sun) !== strtotime('2020-01-13 12:00:00')) {
    echo "fail sunday next week\n";
    exit(1);
}
echo "ok\n";
--EXPECT--
ok
