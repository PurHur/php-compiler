--TEST--
stdlib date_sunrise() — inline SUNFUNCS_RET_STRING (#13749, ext/date/php_date.c)
--FILE--
<?php
$s = date_sunrise(time(), SUNFUNCS_RET_STRING, 40.7, -74.0, 90, 1);
echo is_string($s) ? 'string' : gettype($s), "\n";
echo preg_match('/^\d{2}:\d{2}$/', (string) $s) ? "hhmm\n" : "bad\n";
--EXPECT--
string
hhmm
