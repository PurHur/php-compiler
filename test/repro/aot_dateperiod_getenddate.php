<?php
// Repro #27572 — AOT DatePeriod::getEndDate() (+ peer accessors).
$start = new DateTime('2020-01-01');
$interval = new DateInterval('P1D');
$end = new DateTime('2020-01-05');
$p = new DatePeriod($start, $interval, $end);
echo $p->getDateInterval()->d, "\n";
echo $p->getStartDate()->format('Y-m-d'), "\n";
echo $p->getEndDate()->format('Y-m-d'), "\n";
