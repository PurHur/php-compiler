--TEST--
stdlib fputs() — alias of fwrite() (#6162, ext/standard/streams.c)
--FILE--
<?php
var_export(function_exists('fputs'));
echo "\n";
var_export(function_exists('fwrite'));
echo "\n";
$fp = fopen('php://memory', 'r+');
$n = fputs($fp, 'hello');
echo $n, "\n";
$n2 = fwrite($fp, 'world', 3);
echo $n2, "\n";
fclose($fp);
--EXPECT--
true
true
5
3
