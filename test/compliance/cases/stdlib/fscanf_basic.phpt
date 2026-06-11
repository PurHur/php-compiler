--TEST--
stdlib fscanf() — formatted stream input (#3284, ext/standard/fscanf.c)
--FILE--
<?php
$fp = fopen('php://memory', 'r+');
fwrite($fp, '42 hello');
rewind($fp);
$n = 0;
$s = '';
$c = fscanf($fp, '%d %s', $n, $s);
echo $c, ' ', $n, ' ', $s, "\n";
$fp2 = fopen('php://memory', 'r+');
fwrite($fp2, '3.14');
rewind($fp2);
$f = 0.0;
echo fscanf($fp2, '%f', $f), ' ', $f, "\n";
--EXPECT--
2 42 hello
1 3.14
