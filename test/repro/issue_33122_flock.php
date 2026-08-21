<?php
// #33122 — thin AOT flock via libc FILE* force (LOCK_EX + LOCK_UN)
$path = sys_get_temp_dir().'/phpc_aot_flock_'.getmypid().'.txt';
$fp = fopen($path, 'c+');
echo flock($fp, LOCK_EX) ? '1' : '0', "\n";
echo flock($fp, LOCK_UN) ? '1' : '0', "\n";
fclose($fp);
@unlink($path);
