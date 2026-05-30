--TEST--
get_resource_type() on fopen stream (#3142)
--FILE--
<?php
$f = fopen('php://memory', 'r+');
echo function_exists('get_resource_type') ? '1' : '0', "\n";
echo get_resource_type($f), "\n";
fclose($f);
--EXPECT--
1
stream
