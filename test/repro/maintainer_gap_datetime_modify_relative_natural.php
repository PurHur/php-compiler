<?php

declare(strict_types=1);

/**
 * Issue #14326 — DateTime::modify() / date_modify() natural-language relative modifiers.
 *
 * php-src: ext/date/php_date.c — php_date_modify(), timelib relative parser
 */

$tz = new DateTimeZone('UTC');
$fail = 0;

$dt = new DateTime('2020-01-01', $tz);
if (!$dt->modify('next monday') || '2020-01-06' !== $dt->format('Y-m-d')) {
    fwrite(STDERR, 'next monday: '.$dt->format('Y-m-d')."\n");
    ++$fail;
}

$dt2 = new DateTime('2020-01-01', $tz);
if (!$dt2->modify('first day of next month') || '2020-02-01' !== $dt2->format('Y-m-d')) {
    fwrite(STDERR, 'first day of next month: '.$dt2->format('Y-m-d')."\n");
    ++$fail;
}

$dt3 = new DateTime('2020-01-01', $tz);
if (!$dt3->modify('+1 day') || '2020-01-02' !== $dt3->format('Y-m-d')) {
    fwrite(STDERR, '+1 day: '.$dt3->format('Y-m-d')."\n");
    ++$fail;
}

$dt4 = new DateTime('2020-01-01', $tz);
$dm = date_modify($dt4, 'next monday');
if (false === $dm || '2020-01-06' !== $dt4->format('Y-m-d')) {
    fwrite(STDERR, 'date_modify next monday: '.$dt4->format('Y-m-d')."\n");
    ++$fail;
}

$dt5 = new DateTime('2020-01-01', $tz);
$bad = $dt5->modify('not a date');
if (false !== $bad || '2020-01-01' !== $dt5->format('Y-m-d')) {
    fwrite(STDERR, 'failed modify should return false and preserve date'."\n");
    ++$fail;
}

echo 0 === $fail ? "ok\n" : "fail\n";
exit(0 === $fail ? 0 : 1);
