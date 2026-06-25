--TEST--
stdlib timezone_name_get() — procedural DateTimeZone name (#11746, ext/date/php_date.c)
--FILE--
<?php
$tz = new DateTimeZone('UTC');
echo timezone_name_get($tz), "\n";
echo timezone_name_get(timezone_open('Europe/Berlin')), "\n";
echo function_exists('timezone_name_get') ? 'exists' : 'missing', "\n";
--EXPECT--
UTC
Europe/Berlin
exists
