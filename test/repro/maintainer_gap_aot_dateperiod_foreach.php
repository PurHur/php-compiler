<?php

declare(strict_types=1);

/**
 * Repro for #26772 — thin AOT DatePeriod foreach + format('Y-m-d').
 *
 * Expect: 2020-01-01,2020-01-02,2020-01-03,2020-01-04
 */
$start = new DateTimeImmutable('2020-01-01');
$end = new DateTimeImmutable('2020-01-05');
$p = new DatePeriod($start, new DateInterval('P1D'), $end);
$out = [];
foreach ($p as $d) {
    $out[] = $d->format('Y-m-d');
}
echo implode(',', $out), "\n";
