--TEST--
stdlib get_debug_type() on open php://memory stream (#5164, ext/standard/type.c)
--FILE--
<?php
$h = fopen('php://memory', 'r+');
echo 'get_debug_type=', get_debug_type($h), "\n";
echo 'gettype=', gettype($h), "\n";
--EXPECT--
get_debug_type=resource (stream)
gettype=resource
