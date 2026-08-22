<?php
/**
 * #33744 — AOT DatePeriod foreach must match Zend (re-#26937 / #26772).
 *
 * php-src: ext/date/php_date.c — date_period_construct / DatePeriod iterator
 */
$start = new DateTimeImmutable('2020-01-01');
$end = new DateTimeImmutable('2020-01-05');
$p = new DatePeriod($start, new DateInterval('P1D'), $end);
$out = [];
foreach ($p as $d) {
    $out[] = $d->format('Y-m-d');
}
echo implode(',', $out), "\n";

$q = new DatePeriod(new DateTime('2020-01-01'), new DateInterval('P1D'), 2);
$out = [];
foreach ($q as $d) {
    $out[] = $d->format('Y-m-d');
}
echo implode(' ', $out), "\n";

$out = [];
foreach (new DatePeriod(new DateTimeImmutable('2020-01-01'), new DateInterval('P1D'), new DateTimeImmutable('2020-01-05')) as $d) {
    $out[] = $d->format('Y-m-d');
}
echo implode(',', $out), "\n";
