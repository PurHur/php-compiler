--TEST--
stdlib timezone_open() JIT returns DateTimeZone (#4634, ext/date/php_date.c)
--JIT--
--FILE--
<?php
$tz = timezone_open('UTC');
var_dump($tz instanceof DateTimeZone);
echo $tz->getName(), "\n";
--EXPECT--
bool(true)
UTC
