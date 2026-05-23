--TEST--
stdlib ftell() returns stream position (issue #1190)
--FILE--
<?php
$path = tempnam(sys_get_temp_dir(), 'phpc_ftell_');
if (!is_string($path)) {
    echo "fail\n";
    return;
}
$fp = fopen($path, 'w');
echo ftell($fp) === 0 ? '0' : 'n', "\n";
fwrite($fp, 'ab');
echo ftell($fp) === 2 ? '2' : 'n', "\n";
fclose($fp);
@unlink($path);
--EXPECT--
0
2
