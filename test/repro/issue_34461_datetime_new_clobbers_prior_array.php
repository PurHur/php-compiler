<?php
/**
 * #34461 — AOT must not rebind a prior `$out = []` when publishing unnamed DateTime New_ stamps.
 *
 * php-src: ext/date/php_date.c — DateTime::__construct / DatePeriod nested DateTimeImmutable
 */
$out = [];
$p = new DateTimeImmutable('2020-01-01');
$out[] = 'x';
echo count($out), "\n";

$a = [];
$period = new DatePeriod(
    new DateTimeImmutable('2020-01-01'),
    new DateInterval('P1D'),
    new DateTimeImmutable('2020-01-05')
);
$a[] = 'y';
echo count($a), "\n";

$dates = [];
foreach (new DatePeriod(
    new DateTimeImmutable('2020-01-01'),
    new DateInterval('P1D'),
    new DateTimeImmutable('2020-01-05')
) as $d) {
    $dates[] = $d->format('Y-m-d');
}
echo implode(',', $dates), "\n";
