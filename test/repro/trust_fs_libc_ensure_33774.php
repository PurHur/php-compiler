<?php
$dir = sys_get_temp_dir() . '/phpc33774_' . getmypid();
@mkdir($dir, 0777, true);
$file = $dir . '/a.txt';
file_put_contents($file, 'x');
$ok = copy($file, $dir . '/b.txt');
touch($dir . '/b.txt');
@chown($dir . '/b.txt', getmyuid());
echo ($ok && is_file($dir . '/b.txt')) ? "ok\n" : "fail\n";
@unlink($dir . '/a.txt');
@unlink($dir . '/b.txt');
@rmdir($dir);
