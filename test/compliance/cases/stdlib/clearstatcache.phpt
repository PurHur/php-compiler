--TEST--
stdlib clearstatcache()
--FILE--
<?php
$path = tempnam(sys_get_temp_dir(), 'phpc_clearstatcache_');
if (!is_string($path)) {
    echo 'notemp', "\n";
    return;
}
touch($path);
$r0 = clearstatcache();
$r1 = clearstatcache(true);
$r2 = clearstatcache(true, $path);
$ok = is_file($path);
@unlink($path);
echo null === $r0 ? 'n0' : 'v0', "\n";
echo null === $r1 ? 'n1' : 'v1', "\n";
echo null === $r2 ? 'n2' : 'v2', "\n";
echo $ok ? 'file' : 'nofile', "\n";
--EXPECT--
n0
n1
n2
file
