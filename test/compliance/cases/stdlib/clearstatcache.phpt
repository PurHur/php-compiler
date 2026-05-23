--TEST--
stdlib clearstatcache()
--FILE--
<?php
clearstatcache();
clearstatcache(false);
$path = tempnam(sys_get_temp_dir(), 'phpc_clearstatcache_');
if (!is_string($path)) {
    echo 'notemp', "\n";
    return;
}
touch($path);
$r = clearstatcache(true, $path);
$ok = is_file($path);
@unlink($path);
echo null === $r ? 'null' : 'val', "\n";
echo $ok ? 'file' : 'nofile', "\n";
--EXPECT--
null
file
