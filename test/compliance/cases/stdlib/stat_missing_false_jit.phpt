--TEST--
JIT stat()/lstat() missing path — false not NULL under @ (#10336, ext/standard/stat.c)
--FILE--
<?php
var_export(@stat('/no/such/phpc-maintainer-stat'));
echo "\n";
var_export(@lstat('/no/such/phpc-maintainer-stat'));
echo "\n";
--EXPECT--
false
false
