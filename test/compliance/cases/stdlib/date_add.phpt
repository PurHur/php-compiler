--TEST--
stdlib date_add/date_sub/date_modify/date_diff — procedural DateTime mutation (#4604)
--FILE--
<?php
foreach (['date_add', 'date_sub', 'date_modify', 'date_diff'] as $f) {
    echo function_exists($f) ? '1' : '0';
}
echo "\n";

$dt = new DateTime('2026-06-01 12:00:00', new DateTimeZone('UTC'));
$interval = new DateInterval('P1D');
date_add($dt, $interval);
echo $dt->format('Y-m-d'), "\n";

$dt2 = new DateTime('2026-06-01', new DateTimeZone('UTC'));
date_modify($dt2, '+2 days');
echo $dt2->format('Y-m-d'), "\n";

$a = new DateTime('2026-06-01', new DateTimeZone('UTC'));
$b = new DateTime('2026-06-03', new DateTimeZone('UTC'));
$diff = date_diff($a, $b);
echo $diff->days, ' ', $diff->invert, "\n";

$dt3 = new DateTime('2026-06-03', new DateTimeZone('UTC'));
$interval2 = new DateInterval('P1D');
$interval2->invert = 1;
date_sub($dt3, $interval2);
echo $dt3->format('Y-m-d'), "\n";
?>
--EXPECT--
1111
2026-06-02
2026-06-03
2 0
2026-06-04
