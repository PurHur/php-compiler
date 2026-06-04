<?php

declare(strict_types=1);

echo function_exists('memory_reset_peak_usage') ? "exists\n" : "missing\n";

$peakBefore = memory_get_peak_usage();
$buf = str_repeat('x', 100000);
$peakAfterGrow = memory_get_peak_usage();
unset($buf);
memory_reset_peak_usage();
$peakAfterReset = memory_get_peak_usage();
echo ($peakAfterGrow >= $peakBefore) ? "grew\n" : "no_grow\n";
echo ($peakAfterReset <= $peakAfterGrow) ? "reset_lower\n" : "reset_high\n";
echo ($peakAfterReset >= memory_get_usage()) ? "baseline_ok\n" : "baseline_bad\n";
