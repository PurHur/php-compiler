--TEST--
AOT: file_put_contents() writes a string and readfile streams it back
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/file_put_contents_jit_fixture/aot.txt';
$n = file_put_contents($path, 'aot');
echo $n, "\n";
readfile($path);
--EXPECT--
3
aot
