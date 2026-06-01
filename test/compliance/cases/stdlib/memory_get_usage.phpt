--TEST--
stdlib memory_get_usage/memory_get_peak_usage — grow and peak ordering (issue #3134)
--FILE--
<?php
$before = memory_get_usage();
$buf = str_repeat('x', 10000);
$after = memory_get_usage();
$peak = memory_get_peak_usage();
echo ($after > $before) ? "grew\n" : "flat\n";
echo ($peak >= $after) ? "peak_ok\n" : "peak_bad\n";
$real = memory_get_usage(true);
echo ($real > 0) ? "real_ok\n" : "real_zero\n";
--EXPECT--
grew
peak_ok
real_ok
