--TEST--
stdlib date_create(null) / DateTime(null) — null datetime means now (#18682, ext/date/php_date.c)
--FILE--
<?php
$d = date_create(null);
echo $d instanceof DateTime ? "date_create\n" : "bad\n";

$dt = new DateTime(null);
echo $dt instanceof DateTime ? "DateTime\n" : "bad\n";

$di = new DateTimeImmutable(null);
echo $di instanceof DateTimeImmutable ? "DateTimeImmutable\n" : "bad\n";

$dim = date_create_immutable(null);
echo $dim instanceof DateTimeImmutable ? "date_create_immutable\n" : "bad\n";
?>
--EXPECT--
date_create
DateTime
DateTimeImmutable
date_create_immutable
