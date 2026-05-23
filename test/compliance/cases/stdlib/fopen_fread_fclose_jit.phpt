--TEST--
JIT: fopen(), fread(), and fclose() via __compiler stream runtime
--FILE--
<?php
$base = 'test/compliance/cases/stdlib/fopen_fread_fclose_jit_fixture';
@mkdir($base);
$path = $base.'/sample.txt';
file_put_contents($path, 'stream');
$fp = fopen($path, 'rb');
$data = fread($fp, 6);
$closed = fclose($fp);
@unlink($path);
@rmdir($base);
echo is_string($data) ? $data : 'read_fail', "\n";
echo $closed ? "closed\n" : "close_fail\n";
--EXPECT--
stream
closed
