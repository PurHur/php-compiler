--TEST--
stdlib DatePeriod inline DateTime temps + INCLUDE_END_DATE (#19731, re-#14228, ext/date/php_date.c)
--FILE--
<?php
$out = [];
foreach (new DatePeriod(
    new DateTime('2020-01-01'),
    new DateInterval('P1D'),
    new DateTime('2020-01-03'),
    DatePeriod::INCLUDE_END_DATE
) as $d) {
    $out[] = $d->format('Y-m-d');
}
echo 'mutable=', implode(',', $out), "\n";

$outImm = [];
foreach (new DatePeriod(
    new DateTimeImmutable('2020-01-01'),
    new DateInterval('P1D'),
    new DateTimeImmutable('2020-01-03'),
    DatePeriod::INCLUDE_END_DATE
) as $d) {
    $outImm[] = $d->format('Y-m-d');
}
echo 'immutable=', implode(',', $outImm), "\n";

$start = new DateTime('2020-01-01');
$interval = new DateInterval('P1D');
$end = new DateTime('2020-01-03');
$outLocal = [];
foreach (new DatePeriod($start, $interval, $end, DatePeriod::INCLUDE_END_DATE) as $d) {
    $outLocal[] = $d->format('Y-m-d');
}
echo 'locals=', implode(',', $outLocal), "\n";
--EXPECT--
mutable=2020-01-01,2020-01-02,2020-01-03
immutable=2020-01-01,2020-01-02,2020-01-03
locals=2020-01-01,2020-01-02,2020-01-03
