--TEST--
AOT: fputs() alias registers and matches fwrite() (#6162)
--FILE--
<?php
echo function_exists('fputs') ? "1" : "0", "\n";
$fp = fopen('php://memory', 'r+');
$w = fputs($fp, 'aot');
echo (false === $w ? '0' : (string) $w), "\n";
fclose($fp);
--EXPECT--
1
3
