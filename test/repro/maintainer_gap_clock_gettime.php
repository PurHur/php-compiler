<?php

declare(strict_types=1);

echo (int) function_exists('clock_gettime'), "\n";
echo (int) enum_exists('ClockInterface'), "\n";
$rt = clock_gettime();
echo is_array($rt) && isset($rt['sec'], $rt['nsec']) ? 'shape-ok' : 'shape-bad', "\n";
echo $rt['sec'] >= 0 ? 'sec-ok' : 'sec-bad', "\n";
$mono = clock_gettime(ClockInterface::Monotonic);
echo is_array($mono) && isset($mono['sec'], $mono['nsec']) ? 'mono-ok' : 'mono-bad', "\n";
