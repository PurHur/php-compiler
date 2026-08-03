--TEST--
AOT ftok() System V IPC key matches stable int (#27389)
--FILE--
<?php
$path = tempnam(sys_get_temp_dir(), 'ftok_aot_');
$key = ftok($path, 'a');
echo is_int($key) && $key !== -1 ? "ok\n" : "bad\n";
$key2 = ftok($path, 'a');
echo $key === $key2 ? "stable\n" : "bad\n";
@unlink($path);
--EXPECT--
ok
stable
