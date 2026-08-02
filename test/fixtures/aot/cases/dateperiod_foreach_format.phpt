--TEST--
AOT: DatePeriod foreach + DateTimeImmutable::format('Y-m-d') (#26772)
--FILE--
<?php
$start = new DateTimeImmutable('2020-01-01');
$end = new DateTimeImmutable('2020-01-05');
$p = new DatePeriod($start, new DateInterval('P1D'), $end);
$out = [];
foreach ($p as $d) {
    $out[] = $d->format('Y-m-d');
}
echo implode(',', $out), "\n";
--EXPECT--
2020-01-01,2020-01-02,2020-01-03,2020-01-04
