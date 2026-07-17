--TEST--
stdlib DateTime::setISODate()/DateTimeImmutable::setISODate() ISO week-year (#19847, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);

$dt = new DateTime('2020-01-01 12:34:56', new DateTimeZone('UTC'));
$dt->setISODate(2020, 1, 1);
echo $dt->format('Y-m-d H:i:s'), "\n";
$dt->setISODate(2020, 2);
echo $dt->format('Y-m-d'), "\n";
$dt->setISODate(2020, 53, 7);
echo $dt->format('Y-m-d'), "\n";

$immutable = new DateTimeImmutable('2020-01-01 10:30:45', new DateTimeZone('UTC'));
$updated = $immutable->setISODate(2020, 2, 1);
echo $updated->format('Y-m-d H:i:s'), "\n";
echo $immutable->format('Y-m-d'), "\n";
--EXPECT--
2019-12-30 12:34:56
2020-01-06
2021-01-03
2020-01-06 10:30:45
2020-01-01
