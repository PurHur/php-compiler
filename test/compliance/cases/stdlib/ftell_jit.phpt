--TEST--
stdlib ftell() JIT returns stream position
--FILE--
<?php
$path = tempnam(sys_get_temp_dir(), 'phpc_ftell_jit_');
$fp = fopen($path, 'w');
echo ftell($fp) === 0 ? '0' : 'n', "\n";
fwrite($fp, 'x');
echo ftell($fp) === 1 ? '1' : 'n', "\n";
fclose($fp);
@unlink($path);
--EXPECT--
0
1
