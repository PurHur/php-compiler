<?php

/**
 * Repro #30934 — DatePeriod accessor excess argc → ArgumentCountError.
 * php-src: ext/date/php_date.c — zim_DatePeriod_get*
 */
$p = new DatePeriod(new DateTime('2020-01-01'), new DateInterval('P1D'), 2);
$pEnd = new DatePeriod(new DateTime('2020-01-01'), new DateInterval('P1D'), new DateTime('2020-01-03'));
foreach ([
    'interval' => static fn () => $p->getDateInterval(1),
    'start' => static fn () => $p->getStartDate(1),
    'end' => static fn () => $pEnd->getEndDate(1),
    'rec' => static fn () => $p->getRecurrences(1),
] as $name => $call) {
    try {
        $r = $call();
        echo $name, ':', is_object($r) ? get_class($r) : var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $name, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}
$okI = $p->getDateInterval();
$okS = $p->getStartDate();
$okE = $pEnd->getEndDate();
$okR = $p->getRecurrences();
echo 'ok=', (
    $okI instanceof DateInterval
    && $okS instanceof DateTime
    && $okE instanceof DateTime
    && 2 === $okR
) ? '1' : '0', "\n";
