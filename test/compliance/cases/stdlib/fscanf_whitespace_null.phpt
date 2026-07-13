--TEST--
stdlib fscanf() whitespace-only stream — null not array (#18443, ext/standard/formatted_io.c)
--FILE--
<?php
$h = fopen('php://memory', 'r+');
fwrite($h, ' ');
rewind($h);
$r = fscanf($h, '%d');
fclose($h);
var_export($r);
echo "\n";
echo empty($r) ? 'empty' : 'not-empty', "\n";
--EXPECT--
NULL
empty
