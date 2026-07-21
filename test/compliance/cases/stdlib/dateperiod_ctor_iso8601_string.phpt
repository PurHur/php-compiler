--TEST--
stdlib DatePeriod::__construct(string $isostr) ISO-8601 (#21939, ext/date/php_date.c)
--FILE--
<?php
$p = new DatePeriod('R1/2020-01-01T00:00:00Z/P1D');
echo 'count=', iterator_count($p), "\n";
$dates = [];
foreach ($p as $d) {
    $dates[] = $d->format('Y-m-d');
}
echo implode(',', $dates), "\n";
$p2 = new DatePeriod('R1/2020-01-01T00:00:00Z/P1D', DatePeriod::EXCLUDE_START_DATE);
echo 'excl=', iterator_count($p2), "\n";
try {
    new DatePeriod('not-a-period');
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
$start = new DateTime('2020-01-01');
$interval = new DateInterval('P1D');
$periodCount = new DatePeriod($start, $interval, 3);
$outCount = '';
foreach ($periodCount as $d) {
    $outCount .= $d->format('d');
}
echo $outCount, "\n";
--EXPECT--
count=2
2020-01-01,2020-01-02
excl=1
DateMalformedPeriodStringException
01020304
