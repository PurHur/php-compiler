--TEST--
stdlib usort() strcmp callback JIT
--FILE--
<?php
$routes = explode(',', 'b,a,c');
usort($routes, 'strcmp');
echo implode(',', $routes), "\n";
--EXPECT--
a,b,c
