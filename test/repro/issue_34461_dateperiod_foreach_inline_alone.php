<?php
/**
 * #34461 — standalone inline foreach (new DatePeriod(...)) must AOT-compile (re-#33744).
 *
 * php-src: ext/date/php_date.c — date_period_it_* / DatePeriod Traversable
 */
$out = [];
foreach (new DatePeriod(new DateTimeImmutable('2020-01-01'), new DateInterval('P1D'), new DateTimeImmutable('2020-01-05')) as $d) {
    $out[] = $d->format('Y-m-d');
}
echo implode(',', $out), "\n";
