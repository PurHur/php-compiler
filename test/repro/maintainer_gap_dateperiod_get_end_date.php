<?php

declare(strict_types=1);

/**
 * Maintainer gap: DatePeriod::getEndDate() missing (#17495).
 */

$start = new DateTime('2020-01-01');
$interval = new DateInterval('P1D');

$recurrence = new DatePeriod($start, $interval, 3);
if (!method_exists($recurrence, 'getEndDate')) {
    echo "fail: getEndDate missing\n";
    exit(1);
}
if (null !== $recurrence->getEndDate()) {
    echo "fail: recurrence-count period end should be null\n";
    exit(1);
}

$end = new DateTime('2020-01-05');
$bounded = new DatePeriod($start, $interval, $end);
$gotEnd = $bounded->getEndDate();
if (!($gotEnd instanceof DateTimeInterface)) {
    echo "fail: end-date period should return DateTimeInterface\n";
    exit(1);
}
if ('2020-01-05' !== $gotEnd->format('Y-m-d')) {
    echo "fail: end date mismatch\n";
    exit(1);
}

echo "ok\n";
