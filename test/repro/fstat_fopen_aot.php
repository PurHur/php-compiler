<?php
// Repro — thin AOT procedural fstat(fopen) (Module.php:180 / wrong empty).
$p = sys_get_temp_dir() . '/phpc_fstat_fopen_' . getmypid() . '.txt';
file_put_contents($p, 'ab');
$h = fopen($p, 'r');
$st = fstat($h);
echo is_array($st) ? ('size=' . $st['size']) : ('not-array:' . gettype($st));
echo "\n";
fclose($h);
@unlink($p);
