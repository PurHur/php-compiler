--TEST--
stdlib date_offset_get() — procedural DateTime offset (#11876, ext/date/php_date.c)
--FILE--
<?php
$dt = new DateTime('2020-06-01', new DateTimeZone('America/New_York'));
echo date_offset_get($dt), "\n";
echo function_exists('date_offset_get') ? 'exists' : 'missing', "\n";
--EXPECT--
-14400
exists
