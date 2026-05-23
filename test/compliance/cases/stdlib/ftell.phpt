--TEST--
stdlib ftell() after fwrite
--FILE--
<?php
$path = tempnam(sys_get_temp_dir(), 'phpc_ftell_');
if (!is_string($path)) {
    echo "0\n";
    return;
}
$fp = fopen($path, 'w');
echo ftell($fp), "\n";
$n = fwrite($fp, 'abc');
echo ftell($fp), "\n";
fclose($fp);
@unlink($path);
--EXPECT--
0
3
