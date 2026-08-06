--TEST--
stdlib memory_reset_peak_usage real peak lowers after free (#26769, re-#7310)
--FILE--
<?php
$big = str_repeat('x', 5 * 1024 * 1024);
$peak1 = memory_get_peak_usage(true);
unset($big);
memory_reset_peak_usage();
$peak2 = memory_get_peak_usage(true);
echo ($peak2 < $peak1) ? "real_lowered=yes\n" : "real_lowered=no\n";
echo (memory_get_usage(true) > 0) ? "real_pos\n" : "real_zero\n";
--EXPECT--
real_lowered=yes
real_pos
