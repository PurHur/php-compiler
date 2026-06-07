--TEST--
stdlib fdatasync() on writable regular-file stream (#6813, ext/standard/streamsfuncs.c)
--FILE--
<?php
$path = tempnam(sys_get_temp_dir(), 'phpc_fdatasync_');
if (!is_string($path)) {
    echo "fail\n";
    return;
}
$fp = fopen($path, 'w');
echo fwrite($fp, 'x') === 1 ? 'w' : 'n', "\n";
echo fdatasync($fp) ? '1' : '0', "\n";
fclose($fp);
@unlink($path);
--EXPECT--
w
1
