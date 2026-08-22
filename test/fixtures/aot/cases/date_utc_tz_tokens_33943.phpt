--TEST--
AOT: date()/gmdate() UTC timezone tokens T/e/O/P/r (#33943)
--FILE--
<?php
$ts = strtotime('2024-01-15 12:00:00');
echo date('T', $ts), "\n";
echo date('e', $ts), "\n";
echo date('O', $ts), "\n";
echo date('P', $ts), "\n";
echo date('r', $ts), "\n";
echo gmdate('T', 0), "\n";
--EXPECT--
UTC
UTC
+0000
+00:00
Mon, 15 Jan 2024 12:00:00 +0000
GMT
