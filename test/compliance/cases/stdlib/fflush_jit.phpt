--TEST--
stdlib fflush() JIT
--FILE--
<?php
$path = tempnam(sys_get_temp_dir(), 'phpc_fflush_jit_');
if (!is_string($path)) {
    echo "0\n";
    return;
}
$fp = fopen($path, 'w');
echo fflush($fp) ? '1' : '0', "\n";
fclose($fp);
@unlink($path);
--EXPECT--
1
