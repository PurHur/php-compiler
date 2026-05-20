--TEST--
stdlib time(), date(), and gmdate() JIT/AOT path
--FILE--
<?php
$ts = 946684800;
echo gmdate('Y-m-d', $ts), ' ', gmdate('H:i:s', $ts), "\n";
echo gmdate('H', $ts), "\n";
echo strlen(gmdate('Y', $ts)), "\n";
$t = time();
echo $t > 946684800 ? "ok\n" : "bad\n";
--EXPECT--
2000-01-01 00:00:00
00
4
ok
