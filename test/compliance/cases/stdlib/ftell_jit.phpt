--TEST--
stdlib ftell() JIT
--FILE--
<?php
$path = tempnam(sys_get_temp_dir(), 'phpc_ftell_jit_');
if (!is_string($path)) {
    echo "0\n";
    return;
}
$fp = fopen($path, 'w');
$n = fwrite($fp, 'xy');
echo ftell($fp), "\n";
fclose($fp);
@unlink($path);
--EXPECT--
2
