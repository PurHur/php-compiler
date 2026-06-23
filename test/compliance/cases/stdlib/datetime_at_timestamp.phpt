--TEST--
stdlib DateTime parses @unix timestamp strings (#10858, ext/date/php_date.c)
--FILE--
<?php
date_default_timezone_set('UTC');
$dt = new DateTime('@1700000000');
echo $dt->getTimestamp(), "\n";
$di = new DateTimeImmutable('@42');
echo $di->getTimestamp(), "\n";
--EXPECT--
1700000000
42
