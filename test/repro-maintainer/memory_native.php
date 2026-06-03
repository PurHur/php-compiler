<?php

declare(strict_types=1);

$before = memory_get_usage();
$buf = str_repeat('x', 1024);
$after = memory_get_usage();
echo $after >= $before ? "grow\n" : "fail\n";
echo memory_get_peak_usage() >= $after ? "peak\n" : "fail\n";
echo memory_get_usage(true) > 0 ? "real\n" : "fail\n";
