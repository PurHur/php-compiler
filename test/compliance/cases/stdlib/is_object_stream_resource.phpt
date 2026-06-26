--TEST--
stdlib is_object() — stream resources are not objects (#12302, ext/standard/type.c)
--FILE--
<?php
$h = fopen('php://memory', 'r+');
var_export(is_object($h));
echo "\n";
var_export(is_resource($h));
echo "\n";
echo gettype($h), "\n";
fclose($h);
var_export(is_object($h));
echo "\n";
var_export(is_resource($h));
echo "\n";
echo gettype($h), "\n";
--EXPECT--
false
true
resource
false
false
resource (closed)
