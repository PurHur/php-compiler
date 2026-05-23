--TEST--
stdlib usort() with strcmp on string list arrays
--FILE--
<?php
$routes = ['b', 'a', 'c'];
usort($routes, 'strcmp');
echo implode(',', $routes), "\n";
--EXPECT--
a,b,c
