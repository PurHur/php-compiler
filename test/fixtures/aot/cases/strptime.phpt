--TEST--
AOT: strptime() parse date/time string (#3694, ext/standard/datetime.c)
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
$r = strptime('2026-05-30', '%Y-%m-%d');
echo $r['tm_mday'], ' ', $r['tm_mon'], ' ', $r['tm_year'], "\n";
echo $r['unparsed'], "\n";
--EXPECT--
30 4 126

