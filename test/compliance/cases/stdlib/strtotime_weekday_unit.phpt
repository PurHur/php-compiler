--TEST--
stdlib strtotime()/DateTime::modify() relative unit weekday/weekdays (#25262, ext/date/lib/parse_date.re)
--FILE--
<?php
declare(strict_types=1);
date_default_timezone_set('UTC');
$base = strtotime('2020-01-15 12:00:00'); // Wednesday
$cases = [
    '+1 weekday' => '2020-01-16 12:00:00 Thu',
    '+2 weekdays' => '2020-01-17 12:00:00 Fri',
    '-1 weekday' => '2020-01-14 12:00:00 Tue',
    'next weekday' => '2020-01-16 00:00:00 Thu',
    'previous weekday' => '2020-01-14 00:00:00 Tue',
    '+5 weekdays' => '2020-01-22 12:00:00 Wed',
    '-3 weekdays' => '2020-01-10 12:00:00 Fri',
    '+0 weekday' => '2020-01-15 12:00:00 Wed',
    'this weekday' => '2020-01-15 00:00:00 Wed',
    'last weekday' => '2020-01-14 00:00:00 Tue',
];
foreach ($cases as $input => $expected) {
    $ts = strtotime($input, $base);
    $got = is_int($ts) ? date('Y-m-d H:i:s D', $ts) : 'false';
    echo $input, '=', $got, ($got === $expected ? '' : ' FAIL want '.$expected), "\n";
}
$sat = strtotime('2020-01-18 12:00:00');
echo 'sat+1=', date('Y-m-d D', strtotime('+1 weekday', $sat)), "\n";
echo 'fri+1=', date('Y-m-d D', strtotime('+1 weekday', strtotime('2020-01-17 12:00:00'))), "\n";
$dt = new DateTime('2020-01-15 12:00:00', new DateTimeZone('UTC'));
$dt->modify('+1 weekday');
echo 'modify=', $dt->format('Y-m-d H:i:s D'), "\n";
$dt2 = new DateTime('2020-01-15 12:00:00', new DateTimeZone('UTC'));
$dt2->modify('next weekday');
echo 'modify-next=', $dt2->format('Y-m-d H:i:s D'), "\n";
echo 'compound=', date('Y-m-d H:i:s D', strtotime('+1 weekday +1 day', $base)), "\n";
echo 'abs-rel=', date('Y-m-d H:i:s D', strtotime('2020-01-15 +1 weekday')), "\n";
--EXPECT--
+1 weekday=2020-01-16 12:00:00 Thu
+2 weekdays=2020-01-17 12:00:00 Fri
-1 weekday=2020-01-14 12:00:00 Tue
next weekday=2020-01-16 00:00:00 Thu
previous weekday=2020-01-14 00:00:00 Tue
+5 weekdays=2020-01-22 12:00:00 Wed
-3 weekdays=2020-01-10 12:00:00 Fri
+0 weekday=2020-01-15 12:00:00 Wed
this weekday=2020-01-15 00:00:00 Wed
last weekday=2020-01-14 00:00:00 Tue
sat+1=2020-01-20 Mon
fri+1=2020-01-20 Mon
modify=2020-01-16 12:00:00 Thu
modify-next=2020-01-16 00:00:00 Thu
compound=2020-01-17 12:00:00 Fri
abs-rel=2020-01-16 00:00:00 Thu
