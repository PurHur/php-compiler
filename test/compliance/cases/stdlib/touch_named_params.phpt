--TEST--
stdlib touch() named mtime:/atime: arguments (#9523, ext/standard/filestat.c)
--FILE--
<?php
$f = sys_get_temp_dir() . '/phpc_touch_named_' . getmypid();
$mtime = 1000000100;
$atime = 1000000200;
touch($f, mtime: $mtime, atime: $atime);
$s = stat($f);
var_export($s['mtime'] === $mtime && $s['atime'] === $atime);
@unlink($f);
--EXPECT--
true
