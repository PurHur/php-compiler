--TEST--
stdlib stat cache — touch() after stat miss refreshes fileatime (issue #7436)
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc_stat_cache_touch_' . getmypid();
@unlink($path);

$miss = fileatime($path);
$ok = touch($path);
$hit = fileatime($path);
$size = filesize($path);
$mtime = filemtime($path);
$perms = fileperms($path);

@unlink($path);

echo $miss === false ? 'miss-false' : 'miss-other', "\n";
echo $ok ? 'touch-ok' : 'touch-fail', "\n";
echo is_int($hit) && $hit > 0 ? 'hit-int' : 'hit-other', "\n";
echo is_int($size) ? 'size-int' : 'size-other', "\n";
echo is_int($mtime) && $mtime > 0 ? 'mtime-int' : 'mtime-other', "\n";
echo is_int($perms) ? 'perms-int' : 'perms-other', "\n";
--EXPECT--
miss-false
touch-ok
hit-int
size-int
mtime-int
perms-int
