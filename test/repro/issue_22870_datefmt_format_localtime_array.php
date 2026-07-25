<?php
/**
 * Repro #22870 — IntlDateFormatter::format / datefmt_format accept localtime() arrays.
 * Expect: '2024-07-24' twice (Zend php-src dateformat_format.c).
 */
$ts = strtotime('2024-07-24 12:00:00 UTC');
$fmt = datefmt_create(
    'en_US',
    IntlDateFormatter::SHORT,
    IntlDateFormatter::NONE,
    'UTC',
    IntlDateFormatter::GREGORIAN,
    'yyyy-MM-dd'
);
$lt = localtime($ts, true);
var_export((new IntlDateFormatter(
    'en_US',
    IntlDateFormatter::SHORT,
    IntlDateFormatter::NONE,
    'UTC',
    IntlDateFormatter::GREGORIAN,
    'yyyy-MM-dd'
))->format($lt));
echo PHP_EOL;
var_export(datefmt_format($fmt, $lt));
echo PHP_EOL;
