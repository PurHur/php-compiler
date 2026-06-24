<?php

declare(strict_types=1);

$peakBefore = memory_get_peak_usage();
$buf = str_repeat('x', 100000);
$peakAfterGrow = memory_get_peak_usage();
unset($buf);
memory_reset_peak_usage();
$peakAfterReset = memory_get_peak_usage();
$usage = memory_get_usage();

echo ($peakAfterReset >= $usage) ? "baseline_ok\n" : "baseline_bad\n";
