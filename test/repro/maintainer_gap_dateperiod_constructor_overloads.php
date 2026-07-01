<?php

declare(strict_types=1);

// Issue #14228: DatePeriod constructor overloads + foreach iteration.
$start = new DateTime('2020-01-01');
$interval = new DateInterval('P1D');
$end = new DateTime('2020-01-05');

$periodEnd = new DatePeriod($start, $interval, $end);
$outEnd = '';
foreach ($periodEnd as $d) {
    $outEnd .= $d->format('Y-m-d').' ';
}
$expectEnd = '2020-01-01 2020-01-02 2020-01-03 2020-01-04 ';
if ($outEnd !== $expectEnd) {
    fwrite(STDERR, "end-date form: expected {$expectEnd}, got {$outEnd}\n");
    exit(1);
}

$periodCount = new DatePeriod($start, $interval, 3);
$outCount = '';
foreach ($periodCount as $d) {
    $outCount .= $d->format('d');
}
if ($outCount !== '01020304') {
    fwrite(STDERR, "recurrence form: expected 01020304, got {$outCount}\n");
    exit(1);
}

echo "ok\n";
