--TEST--
date DateTime('@unix') offset timezone name +00:00 not UTC (#18388, ext/date/php_date.c)
--FILE--
<?php
$dt = new DateTime('@1609459200');
echo $dt->getTimezone()->getName(), "\n";
echo (new DateTimeImmutable('@0'))->getTimezone()->getName(), "\n";
echo json_encode($dt), "\n";
echo $dt->format('c'), "\n";
echo $dt->format('U'), "\n";
?>
--EXPECT--
+00:00
+00:00
{"date":"2021-01-01 00:00:00.000000","timezone_type":1,"timezone":"+00:00"}
2021-01-01T00:00:00+00:00
1609459200
