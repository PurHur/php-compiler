--TEST--
stdlib timezone_open() returns DateTimeZone (#4634, ext/date/php_date.c)
--FILE--
<?php
var_dump(function_exists('timezone_open'));
$tz = timezone_open('UTC');
var_dump($tz instanceof DateTimeZone);
echo $tz->getName(), "\n";
$bad = timezone_open('Invalid/Zone');
var_dump($bad);
--EXPECT--
PHP Warning:  timezone_open(): Unknown or bad timezone (Invalid/Zone)
bool(true)
bool(true)
UTC
bool(false)
