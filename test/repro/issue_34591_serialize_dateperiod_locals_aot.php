<?php
// #34591 — AOT serialize(DatePeriod) with named locals (leftover of #34585).
declare(strict_types=1);

$s = new DateTime('2020-01-01');
$i = new DateInterval('P1D');
$rec = new DatePeriod($s, $i, 2);
echo serialize($rec), "\n";

$e = new DateTime('2020-01-03');
$end = new DatePeriod($s, $i, $e);
echo serialize($end), "\n";
