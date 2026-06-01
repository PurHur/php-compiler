<?php

declare(strict_types=1);

$path = sys_get_temp_dir() . '/phpc_file_aot_probe_' . getmypid() . '.txt';
$n = file_put_contents($path, "one\ntwo\n");
$lines = file($path);
echo count($lines), ':', $lines[0];
unlink($path);
