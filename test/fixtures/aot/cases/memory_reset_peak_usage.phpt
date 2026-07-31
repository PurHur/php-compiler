--TEST--
AOT memory_reset_peak_usage (issue #5539, #26104)
--FILE--
<?php
$peak0 = memory_get_peak_usage();
$buf = str_repeat('b', 80000);
$peak1 = memory_get_peak_usage();
unset($buf);
memory_reset_peak_usage();
$peak2 = memory_get_peak_usage();
echo ($peak1 >= $peak0) ? "grew\n" : "flat\n";
echo ($peak2 <= $peak1) ? "reset_ok\n" : "reset_bad\n";
--EXPECT--
grew
reset_ok
