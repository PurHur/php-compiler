--TEST--
stdlib usort() with strcasecmp on string list arrays (VM)
--FILE--
<?php
$routes = ['B', 'a', 'C'];
usort($routes, 'strcasecmp');
echo implode(',', $routes), "\n";
--EXPECT--
a,B,C
