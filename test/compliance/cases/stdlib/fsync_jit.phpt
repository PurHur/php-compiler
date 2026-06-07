--TEST--
stdlib fsync() JIT (#6062)
--FILE--
<?php
$path = tempnam(sys_get_temp_dir(), 'phpc_fsync_jit_');
if (!is_string($path)) {
    echo "0\n";
    return;
}
$fp = fopen($path, 'w');
echo fsync($fp) ? '1' : '0', "\n";
fclose($fp);
@unlink($path);
--EXPECT--
1
