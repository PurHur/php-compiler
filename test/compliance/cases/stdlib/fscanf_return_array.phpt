--TEST--
stdlib fscanf() — return array without by-ref targets (#9284, ext/standard/fscanf.c)
--FILE--
<?php
$h = fopen('php://memory', 'r+');
fwrite($h, '42');
rewind($h);
$r = fscanf($h, '%d');
fclose($h);
echo isset($r[0]) ? (string) $r[0] : 'null', "\n";

$h = fopen('php://memory', 'r+');
fwrite($h, 'x');
rewind($h);
$r2 = fscanf($h, '%d');
fclose($h);
echo empty($r2) ? 'empty' : 'not-empty', "\n";
--EXPECT--
42
empty
