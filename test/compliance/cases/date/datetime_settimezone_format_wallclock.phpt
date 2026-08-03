--TEST--
date DateTime/DateTimeImmutable setTimezone then format wall-clock (#27142, ext/date/php_date.c)
--FILE--
<?php
$d = new DateTime('2024-01-01 12:00:00', new DateTimeZone('UTC'));
$d->setTimezone(new DateTimeZone('America/New_York'));
echo $d->getOffset(), "\n";
echo $d->format('Y-m-d H:i:s T'), "\n";
$i = (new DateTimeImmutable('2024-01-01 12:00:00', new DateTimeZone('UTC')))
    ->setTimezone(new DateTimeZone('America/New_York'));
echo $i->format('Y-m-d H:i:s T'), "\n";
$ny = new DateTime('2020-06-01 12:00:00', new DateTimeZone('America/New_York'));
echo $ny->format('r'), "\n";
?>
--EXPECT--
-18000
2024-01-01 07:00:00 EST
2024-01-01 07:00:00 EST
Mon, 01 Jun 2020 12:00:00 -0400
