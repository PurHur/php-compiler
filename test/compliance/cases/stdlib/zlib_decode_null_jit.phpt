--TEST--
stdlib zlib_decode(null) JIT — data error warning and false (#19907)
--JIT--
--FILE--
<?php
error_reporting(E_ALL);
echo zlib_decode(gzcompress('hi')), "\n";
$r = zlib_decode(null);
var_export($r);
echo "\n";
?>
--EXPECTF--
hi
PHP Deprecated:  zlib_decode(): Passing null to parameter #1 ($data) of type string is deprecated in %s on line %d
PHP Warning:  zlib_decode(): data error in %s on line %d
false
