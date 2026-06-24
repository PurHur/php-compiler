--TEST--
stdlib fscanf() empty stream with by-ref — false not 0 (#11218, ext/standard/formatted_io.c)
--FILE--
<?php
$f = fopen('php://memory', 'r+');
$r = fscanf($f, '%d', $v);
fclose($f);
var_export($r);
echo "\n";
var_export($v);
--EXPECT--
false
NULL
