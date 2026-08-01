<?php

declare(strict_types=1);

/**
 * Repro #26693 — DateTime::diff / date_diff must populate DateInterval::$f.
 * php-src: ext/date/php_date.c (php_date_diff / timelib_diff)
 */
$a = new DateTime('2020-01-01 00:00:00.000000');
$b = new DateTime('2020-01-01 00:00:00.500000');
$i = $a->diff($b);
echo 'same_second f=', $i->f, ' s=', $i->s, PHP_EOL;

$a2 = new DateTime('2020-01-01 00:00:00.750000');
$b2 = new DateTime('2020-01-01 00:00:01.250000');
$i2 = $a2->diff($b2);
echo 'cross_second f=', $i2->f, ' s=', $i2->s, PHP_EOL;

$a3 = new DateTime('2020-01-01 00:00:00.250000');
$b3 = new DateTime('2020-01-01 00:00:00.100000');
$i3 = $a3->diff($b3);
echo 'invert f=', $i3->f, ' s=', $i3->s, ' invert=', $i3->invert, PHP_EOL;

$imm = new DateTimeImmutable('2020-01-01 00:00:00.000000');
$imm2 = new DateTimeImmutable('2020-01-01 00:00:00.500000');
$i4 = $imm->diff($imm2);
echo 'immutable f=', $i4->f, ' s=', $i4->s, PHP_EOL;

$i5 = date_diff($a, $b);
echo 'date_diff f=', $i5->f, ' s=', $i5->s, PHP_EOL;
