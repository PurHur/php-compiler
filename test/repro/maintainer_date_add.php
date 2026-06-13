<?php
declare(strict_types=1);

$dt = new DateTime('2026-06-01 12:00:00', new DateTimeZone('UTC'));
$interval = new DateInterval('P1D');

date_add($dt, $interval);
echo $dt->format('Y-m-d'), "\n";

$dt2 = new DateTime('2026-06-01', new DateTimeZone('UTC'));
date_modify($dt2, '+2 days');
echo $dt2->format('Y-m-d'), "\n";
