--TEST--
stdlib date() and DateTime::format() with U specifier (#10857, ext/date/php_date.c)
--FILE--
<?php
date_default_timezone_set('UTC');
$ts = 1700000000;
echo date('U', $ts), "\n";
$dt = new DateTime('@'.$ts);
echo $dt->format('U'), "\n";
echo (new DateTime('@0'))->format('U'), "\n";
--EXPECT--
1700000000
1700000000
0
