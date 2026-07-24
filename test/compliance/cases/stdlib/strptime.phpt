--TEST--
stdlib strptime() — parse date/time string to tm array (#3694, ext/standard/datetime.c)
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
$r = strptime('2026-05-30', '%Y-%m-%d');
echo $r['tm_mday'], ' ', $r['tm_mon'], ' ', $r['tm_year'], "\n";
echo $r['unparsed'], "\n";
echo false === strptime('bad', '%Y-%m-%d') ? "fail\n" : "ok\n";
$r2 = strptime('2026-05-30 tail', '%Y-%m-%d');
echo $r2['tm_mday'], ' ', $r2['unparsed'], "\n";
echo function_exists('strptime') ? "yes\n" : "no\n";
--EXPECT--
30 4 126

fail
30  tail
yes
