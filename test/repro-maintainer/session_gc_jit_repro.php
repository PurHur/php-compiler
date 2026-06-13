<?php
$dir = rtrim(sys_get_temp_dir(), '/\\').'/phpc_sessions';
@mkdir($dir, 0700, true);
session_start();
echo (int) session_gc(), "\n";
$stale = $dir.'/sess_gcjit'.dechex(getmypid());
file_put_contents($stale, 'x');
touch($stale, time() - 9999);
echo (int) session_gc(), "\n";
@unlink($stale);
