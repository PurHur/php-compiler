--TEST--
stdlib fflush() on writable handle
--FILE--
<?php
$path = tempnam(sys_get_temp_dir(), 'phpc_fflush_');
if (!is_string($path)) {
    echo "fail\n";
    return;
}
$fp = fopen($path, 'w');
echo fwrite($fp, 'x') === 1 ? 'w' : 'n', "\n";
echo fflush($fp) ? '1' : '0', "\n";
fclose($fp);
@unlink($path);
--EXPECT--
w
1
