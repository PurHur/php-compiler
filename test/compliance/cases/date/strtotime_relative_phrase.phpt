--TEST--
date strtotime() — last|next|this year month/day phrases (#17586, ext/date/lib/parse_date.re)
--FILE--
<?php
declare(strict_types=1);
date_default_timezone_set('UTC');
$base = strtotime('2020-01-15 12:00:00');
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
    if (strtotime($phrase, $base) !== $expected) {
        echo "fail\n";
        exit(1);
    }
}
echo "ok\n";
--EXPECT--
ok
