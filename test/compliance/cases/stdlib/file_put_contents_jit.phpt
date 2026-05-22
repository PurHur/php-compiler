--TEST--
JIT: file_put_contents() writes a string and returns byte count
--FILE--
<?php
$base = 'test/compliance/cases/stdlib/file_put_contents_jit_fixture';
$path = $base . '/out.txt';
$n = file_put_contents($path, 'ok');
echo $n, "\n";
echo file_get_contents($path), "\n";
--EXPECT--
2
ok
