--TEST--
stdlib touch() — negative mtime preserved (#11587, ext/standard/filestat.c)
--FILE--
<?php
$tmp = sys_get_temp_dir() . '/phpc_touch_neg_' . getmypid();
file_put_contents($tmp, 'x');
touch($tmp, -1);
echo filemtime($tmp) === -1 ? "neg-ok\n" : "neg-bad\n";
touch($tmp, 0);
echo filemtime($tmp) === 0 ? "zero-ok\n" : "zero-bad\n";
@unlink($tmp);
--EXPECT--
neg-ok
zero-ok
