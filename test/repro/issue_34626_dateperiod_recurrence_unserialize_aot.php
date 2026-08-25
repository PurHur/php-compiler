<?php
// #34626 — AOT unserialize(serialize(DatePeriod recurrence-count)) foreach must match Zend
// (re-#34608 covered end-date form only; wire recurrences already includes start).
declare(strict_types=1);

$p = new DatePeriod(new DateTime('2020-01-01'), new DateInterval('P1D'), 2);
$u = unserialize(serialize($p));
foreach ($u as $d) {
    echo $d->format('Y-m-d'), ',';
}
echo PHP_EOL;

$p2 = new DatePeriod(
    new DateTime('2020-01-01'),
    new DateInterval('P1D'),
    2,
    DatePeriod::EXCLUDE_START_DATE
);
$u2 = unserialize(serialize($p2));
foreach ($u2 as $d) {
    echo $d->format('Y-m-d'), ',';
}
echo PHP_EOL;
