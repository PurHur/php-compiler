<?php

declare(strict_types=1);

// Issue #14144: json_encode(DatePeriod) — Zend period object JSON wire.
$start = new DateTime('2020-01-01 00:00:00', new DateTimeZone('UTC'));
$interval = new DateInterval('P1D');
$period = new DatePeriod($start, $interval, 3);
echo json_encode($period), "\n";
