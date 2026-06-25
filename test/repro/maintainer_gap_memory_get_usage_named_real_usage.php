<?php

declare(strict_types=1);

$usage = memory_get_usage(real_usage: true);
$peak = memory_get_peak_usage(real_usage: true);
echo 'memory_get_usage_named_ok=', is_int($usage) && $usage > 0 ? '1' : '0', "\n";
echo 'memory_get_peak_usage_named_ok=', is_int($peak) && $peak > 0 ? '1' : '0', "\n";
