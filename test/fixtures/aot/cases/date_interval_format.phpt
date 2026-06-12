--TEST--
AOT date_interval_format() — DateInterval procedural format (#7278 phase 2)
--FILE--
<?php
$interval = new DateInterval('P1D');
echo date_interval_format($interval, '%d'), "\n";
$full = new DateInterval('P1Y2M3DT4H5M6S');
echo date_interval_format($full, '%y %m %d %h %i %s'), "\n";
--EXPECT--
1
1 2 3 4 5 6
