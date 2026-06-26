--TEST--
stdlib: fopen/fread/tmpfile round-trip via StreamIoJitHelper PHP (#9247, ext/standard/file.c)
--FILE--
<?php
declare(strict_types=1);
$path = sys_get_temp_dir().'/phpc_streamio_'.getmypid().'.txt';
file_put_contents($path, 'abc');
$h = fopen($path, 'r');
echo fread($h, 2), "\n";
fclose($h);
$t = tmpfile();
fwrite($t, 'z');
rewind($t);
echo fread($t, 1), "\n";
fclose($t);
unlink($path);
--EXPECT--
ab
z
