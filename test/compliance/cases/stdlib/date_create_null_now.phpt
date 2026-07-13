--TEST--
stdlib date_create(null) — DateTime for now not TypeError (#18682, ext/date/php_date.c)
--FILE--
<?php
$d = date_create(null);
echo $d instanceof DateTime ? 'DateTime' : 'bad', "\n";
echo preg_match('/^\d{4}-\d{2}-\d{2}$/', $d->format('Y-m-d')) ? "date_ok\n" : "date_bad\n";

$di = date_create_immutable(null);
echo $di instanceof DateTimeImmutable ? 'DateTimeImmutable' : 'bad', "\n";

$dt = new DateTime(null);
echo $dt instanceof DateTime ? 'ctor_ok' : 'ctor_bad', "\n";
?>
--EXPECT--
DateTime
date_ok
DateTimeImmutable
ctor_ok
