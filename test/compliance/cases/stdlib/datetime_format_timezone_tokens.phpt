--TEST--
DateTime::format() timezone metadata tokens (#9965, ext/date/php_date.c)
--FILE--
<?php
$d = new DateTime('2020-06-01 12:00:00', new DateTimeZone('America/New_York'));
echo $d->format('T'), "\n";
echo $d->format('e'), "\n";
echo $d->format('P'), "\n";
echo $d->format('O'), "\n";
echo $d->format('Z'), "\n";
echo $d->format('r'), "\n";
$d2 = new DateTime('2020-06-01 12:00:00', new DateTimeZone('+04:00'));
echo $d2->format('T'), "\n";
?>
--EXPECT--
EDT
America/New_York
-04:00
-0400
-14400
Mon, 01 Jun 2020 12:00:00 -0400
GMT+0400
