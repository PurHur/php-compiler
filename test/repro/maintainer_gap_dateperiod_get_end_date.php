<?php
declare(strict_types=1);

$start = new DateTime('2020-01-01');
$interval = new DateInterval('P1D');
$period = new DatePeriod($start, $interval, 3);

if (!method_exists($period, 'getEndDate')) {
    fwrite(STDERR, "getEndDate not registered\n");
    exit(1);
}

$endDate = $period->getEndDate();
if (null !== $endDate) {
    fwrite(STDERR, "recurrence-count period getEndDate should be null, got ".get_debug_type($endDate)."\n");
    exit(1);
}

$end = new DateTime('2020-01-05');
$periodEnd = new DatePeriod($start, $interval, $end);
$gotEnd = $periodEnd->getEndDate();
if (null === $gotEnd) {
    fwrite(STDERR, "end-date period getEndDate should not be null\n");
    exit(1);
}
if ('2020-01-05' !== $gotEnd->format('Y-m-d')) {
    fwrite(STDERR, 'unexpected end date: '.$gotEnd->format('Y-m-d')."\n");
    exit(1);
}

echo "ok\n";
