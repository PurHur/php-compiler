--TEST--
stdlib DATE_* format constants and date(DATE_RFC3339) (ext/date/php_date.c, #11884)
--FILE--
<?php
echo defined('DATE_RFC3339') ? '1' : '0', "\n";
echo DATE_RFC3339, "\n";
echo date(DATE_RFC3339, 0), "\n";
--EXPECT--
1
Y-m-d\TH:i:sP
1970-01-01T00:00:00+00:00
