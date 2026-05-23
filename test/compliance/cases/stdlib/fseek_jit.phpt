--TEST--
stdlib fseek() JIT
--FILE--
<?php
$path = tempnam(sys_get_temp_dir(), 'phpc_fseek_jit_');
if (!is_string($path)) {
    echo "0\n";
    return;
}
$fp = fopen($path, 'w');
fwrite($fp, 'xy');
echo fseek($fp, 1) === 0 ? '1' : '0', "\n";
echo ftell($fp) === 1 ? '1' : '0', "\n";
fclose($fp);
@unlink($path);
--EXPECT--
1
1
