--TEST--
get_resource_type() on closed stream returns Unknown — JIT (#5179)
--JIT--
--FILE--
<?php
$f = fopen('php://memory', 'r+');
fclose($f);
echo get_resource_type($f), "\n";
--EXPECT--
Unknown
