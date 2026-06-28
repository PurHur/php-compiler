--TEST--
JIT: fwrite() via __compiler_fwrite
--FILE--
<?php
$w = fwrite(STDERR, 'ok');
echo (false === $w ? '0' : (string) $w), "\n";
$w2 = fwrite(STDERR, 'x', 1);
echo (false === $w2 ? '0' : (string) $w2), "\n";
--EXPECT--
okx
2
1
