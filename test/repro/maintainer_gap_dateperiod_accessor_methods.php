<?php

$start = new DateTime('2020-01-01');
$interval = new DateInterval('P1D');
$period = new DatePeriod($start, $interval, 3);

$startOut = $period->getStartDate()->format('Y-m-d');
$intervalOut = $period->getDateInterval()->format('P1D');
$recurrencesOut = $period->getRecurrences();

if ('2020-01-01' !== $startOut) {
    echo "start mismatch: {$startOut}\n";
    exit(1);
}
if ('P1D' !== $intervalOut) {
    echo "interval mismatch: {$intervalOut}\n";
    exit(1);
}
if (3 !== $recurrencesOut) {
    echo 'recurrences mismatch: ';
    var_export($recurrencesOut);
    echo "\n";
    exit(1);
}

echo "ok\n";
