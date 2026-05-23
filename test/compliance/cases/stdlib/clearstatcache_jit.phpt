--TEST--
JIT: clearstatcache() is a no-op and returns null
--FILE--
<?php
$path = tempnam(sys_get_temp_dir(), 'phpc_clearstatcache_jit_');
if (!is_string($path)) {
    echo 'notemp', "\n";
    return;
}
touch($path);
$r = clearstatcache();
$ok = is_file($path);
@unlink($path);
echo null === $r ? 'null' : 'val', "\n";
echo $ok ? 'file' : 'nofile', "\n";
--EXPECT--
null
file
