--TEST--
DateTime numeric timezone offset parsing (#12346, ext/date/php_date.c)
--FILE--
<?php
$dt = new DateTime('2020-01-01T00:00:00+05:00');
echo $dt->format('c'), "\n";
$tz = new DateTimeZone('+0530');
echo $tz->getName(), "\n";
?>
--EXPECT--
2020-01-01T00:00:00+05:00
+05:30
