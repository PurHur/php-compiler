--TEST--
JIT: fopen(), fread(), and fclose() via __compiler_fopen / __compiler_fread / __compiler_fclose (#1117)
--FILE--
<?php
$base = 'test/compliance/cases/stdlib/fopen_fread_fclose_jit_fixture';
$path = $base . '/sample.txt';
file_put_contents($path, 'hello');
$h = fopen($path, 'r');
$data = fread($h, 5);
fclose($h);
echo is_string($data) ? $data : '0', "\n";
--EXPECT--
hello
