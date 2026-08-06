<?php
$big = str_repeat('x', 5 * 1024 * 1024);
$peak1 = memory_get_peak_usage(true);
unset($big);
memory_reset_peak_usage();
$peak2 = memory_get_peak_usage(true);
echo 'real_lowered=', ($peak2 < $peak1 ? 'yes' : 'no'), "\n";
