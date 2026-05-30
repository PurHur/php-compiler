--TEST--
AOT getdate() — associative date/time breakdown
--FILE--
<?php
$d = getdate(946684800);
echo $d['year'], '-', $d['mon'], '-', $d['mday'], "\n";
echo $d['hours'], ':', $d['minutes'], ':', $d['seconds'], "\n";
echo $d['wday'], ' ', $d['weekday'], ' ', $d['month'], "\n";
echo $d['yday'], ' ', $d[0], "\n";
--EXPECT--
2000-1-1
0:0:0
6 Saturday January
0 946684800
