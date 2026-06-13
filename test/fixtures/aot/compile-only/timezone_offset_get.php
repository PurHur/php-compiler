--TEST--
AOT compile-only: timezone_offset_get() JIT lowering (#6041 phase 2)
--COMPILE-ONLY--
--FILE--
<?php
$tz = new DateTimeZone('Europe/Berlin');
$dt = new DateTime('2024-06-01T12:00:00', $tz);
echo timezone_offset_get($tz, $dt), "\n";
