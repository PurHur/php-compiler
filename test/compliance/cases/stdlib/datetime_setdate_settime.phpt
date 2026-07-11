--TEST--
stdlib DateTime::setDate()/setTime() mutate in place; DateTimeImmutable returns new instance (#12469, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);

$dt = new DateTime('2020-01-15 10:30:45', new DateTimeZone('UTC'));
$dt->setDate(2021, 6, 1);
echo $dt->format('Y-m-d H:i:s'), "\n";
$dt->setTime(14, 5, 30);
echo $dt->format('Y-m-d H:i:s'), "\n";

$immutable = new DateTimeImmutable('2020-01-15 10:30:45', new DateTimeZone('UTC'));
$updated = $immutable->setDate(2021, 6, 1);
echo $updated->format('Y-m-d'), "\n";
echo $immutable->format('Y-m-d'), "\n";
--EXPECT--
2021-06-01 10:30:45
2021-06-01 14:05:30
2021-06-01
2020-01-15
