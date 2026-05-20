--TEST--
stdlib readfile() streams bytes to stdout
--FILE--
<?php
$base = 'test/compliance/cases/stdlib/readfile_fixture';
$path = $base . '/payload.txt';
$n = readfile($path);
echo "\n", $n, "\n";
echo readfile($base . '/missing.bin') === false ? 'no' : 'yes', "\n";
--EXPECT--
ab
2
no
