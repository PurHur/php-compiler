--TEST--
stdlib touch() invalidates stat cache — filemtime sees new mtime without clearstatcache (issue #25308)
--FILE--
<?php
$f = tempnam(sys_get_temp_dir(), 'phpc_touch_stat_');
touch($f, 100);
$mt1 = filemtime($f);
clearstatcache(true, $f);
touch($f, 200);
$mt2 = filemtime($f);
@unlink($f);
echo "mt1=$mt1\n";
echo "mt2=$mt2\n";
--EXPECT--
mt1=100
mt2=200
