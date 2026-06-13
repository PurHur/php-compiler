--TEST--
stdlib timezone_offset_get JIT lowering (#6041 phase 2, ext/date/php_date.c)
--JIT--
--FILE--
<?php
$tz = new DateTimeZone('Europe/Berlin');
$dt = new DateTime('2024-06-01T12:00:00', $tz);
echo timezone_offset_get($tz, $dt), "\n";
--EXPECT--
7200
