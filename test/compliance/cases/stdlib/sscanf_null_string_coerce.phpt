--TEST--
stdlib sscanf() null $string coerces (#11937, ext/standard/formatted_io.c)
--FILE--
<?php
var_export(sscanf(null, '%d'));
echo "\n";
var_export(sscanf('', '%d'));
echo "\n";
?>
--EXPECT--
NULL
NULL
