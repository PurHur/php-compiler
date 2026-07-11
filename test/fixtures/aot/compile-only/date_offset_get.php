--TEST--
AOT compile-only: date_offset_get() JIT lowering (#11876)
--COMPILE-ONLY--
--FILE--
<?php
$dt = new DateTime('2020-06-01', new DateTimeZone('America/New_York'));
echo date_offset_get($dt), "\n";
