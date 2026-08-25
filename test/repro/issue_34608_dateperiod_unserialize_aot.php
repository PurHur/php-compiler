<?php
// #34608 — AOT unserialize(serialize(DatePeriod)) foreach must match Zend
// (peer #34585 serialize / #34602 DateInterval unserialize fold).
declare(strict_types=1);

$start = new DateTime('2020-01-01');
$end = new DateTime('2020-01-05');
$interval = new DateInterval('P1D');
$p = new DatePeriod($start, $interval, $end);
$s = serialize($p);
$u = unserialize($s);
foreach ($u as $d) {
    echo $d->format('Y-m-d'), PHP_EOL;
}
