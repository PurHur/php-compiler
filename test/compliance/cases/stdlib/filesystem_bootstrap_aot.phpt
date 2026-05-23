--TEST--
stdlib filesystem bootstrap AOT parity (temp file round-trip)
--FILE--
<?php
declare(strict_types=1);
$path = sys_get_temp_dir() . '/phpc_fs_' . (string) getmypid();
@mkdir($path);
$file = $path . '/sample.txt';
file_put_contents($file, 'ok');
$contents = file_get_contents($file);
$exists = file_exists($file);
@unlink($file);
@rmdir($path);
echo is_string($contents) && $exists ? '1' : '0';
echo "\n";
--EXPECT--
1
