--TEST--
JIT: clearstatcache() clears stat cache so is_file() refreshes (#9110)
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc_clearstatcache_jit_' . getmypid();
file_put_contents($path, 'a');
is_file($path);
exec('rm -f ' . escapeshellarg($path));
$before = is_file($path);
clearstatcache();
$after = is_file($path);
$r = clearstatcache();
echo $before ? 'before-true' : 'before-false', "\n";
echo $after ? 'after-true' : 'after-false', "\n";
echo null === $r ? 'null' : 'val', "\n";
--EXPECT--
before-true
after-false
null
