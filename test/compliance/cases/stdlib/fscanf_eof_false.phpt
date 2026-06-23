--TEST--
stdlib fscanf() empty stream — false not empty array (#10979, ext/standard/formatted_io.c)
--FILE--
<?php
$f = fopen('php://memory', 'r+');
$r = fscanf($f, '%s');
fclose($f);
var_export($r);
--EXPECT--
false
