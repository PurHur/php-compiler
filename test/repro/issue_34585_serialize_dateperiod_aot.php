<?php
// #34585 — AOT serialize(DatePeriod) Zend wire (peer #34576 / #34584).
declare(strict_types=1);

$rec = new DatePeriod(new DateTime('2020-01-01'), new DateInterval('P1D'), 2);
echo serialize($rec), "\n";

$end = new DatePeriod(
    new DateTime('2020-01-01'),
    new DateInterval('P1D'),
    new DateTime('2020-01-03')
);
echo serialize($end), "\n";
