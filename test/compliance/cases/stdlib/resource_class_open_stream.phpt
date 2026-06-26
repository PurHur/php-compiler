--TEST--
stdlib Resource object zval — fopen stream (#7073, #7071)
--FILE--
<?php
var_export(class_exists('Resource'));
echo "\n";
$h = fopen('php://memory', 'r+');
var_export($h instanceof Resource);
echo "\n";
var_export(is_object($h));
echo "\n";
echo get_debug_type($h), "\n";
echo gettype($h), "\n";
var_dump(is_resource($h));
fclose($h);
var_dump(is_resource($h));
echo gettype($h), "\n";
--EXPECT--
true
true
false
resource (stream)
resource
bool(true)
bool(false)
resource (closed)
