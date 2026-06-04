--TEST--
stdlib memory_reset_peak_usage — exists and lowers peak after large alloc (issue #5539)
--FILE--
<?php
echo function_exists('memory_reset_peak_usage') ? "exists\n" : "missing\n";
$peak0 = memory_get_peak_usage();
$buf = str_repeat('a', 50000);
$peak1 = memory_get_peak_usage();
unset($buf);
memory_reset_peak_usage();
$peak2 = memory_get_peak_usage();
$usage = memory_get_usage();
echo ($peak1 >= $peak0) ? "grew\n" : "flat\n";
echo ($peak2 <= $peak1) ? "reset_ok\n" : "reset_bad\n";
echo ($peak2 >= $usage) ? "baseline_ok\n" : "baseline_bad\n";
--EXPECT--
exists
grew
reset_ok
baseline_ok
