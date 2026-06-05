--TEST--
stdlib fputs() JIT — alias of fwrite() (#6162)
--FILE--
<?php
$fp = fopen('php://memory', 'r+');
$w = fputs($fp, 'ok');
echo (false === $w ? '0' : (string) $w), "\n";
$w2 = fputs($fp, 'xyz', 1);
echo (false === $w2 ? '0' : (string) $w2), "\n";
fclose($fp);
--EXPECT--
2
1
