--TEST--
stdlib fseek() resets stream position (issue #1191)
--FILE--
<?php
$path = tempnam(sys_get_temp_dir(), 'phpc_fseek_');
if (!is_string($path)) {
    echo "fail\n";
    return;
}
$fp = fopen($path, 'w');
fwrite($fp, 'ab');
echo fseek($fp, 0) === 0 ? 's' : 'n', "\n";
echo ftell($fp) === 0 ? '0' : 'n', "\n";
fclose($fp);
@unlink($path);
echo fseek(-999, 0) === -1 ? 'f' : 'n', "\n";
--EXPECT--
s
0
f
