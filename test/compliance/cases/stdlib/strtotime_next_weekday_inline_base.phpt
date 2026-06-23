--TEST--
stdlib strtotime() next weekday with inline base timestamp (#10838, ext/date/php_date.c)
--FILE--
<?php
date_default_timezone_set('UTC');
echo strtotime('next Thursday', strtotime('2026-06-01')), "\n";
$mondayBase = strtotime('2024-06-03');
echo strtotime('next Monday', $mondayBase), "\n";
--EXPECT--
1780531200
1717977600
