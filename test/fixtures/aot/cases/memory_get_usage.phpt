--TEST--
AOT memory_get_usage/memory_get_peak_usage (issue #3134)
--FILE--
<?php
$usage = memory_get_usage();
$peak = memory_get_peak_usage();
$real = memory_get_usage(true);
echo ($usage > 0) ? "usage_ok\n" : "usage_zero\n";
echo ($peak >= $usage) ? "peak_ok\n" : "peak_bad\n";
echo ($real > 0) ? "real_ok\n" : "real_zero\n";
--EXPECT--
usage_ok
peak_ok
real_ok
