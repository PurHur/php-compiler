--TEST--
stdlib sort() on string list arrays
--FILE--
<?php
$routes = ['b', 'a', 'c'];
sort($routes);
echo implode(',', $routes), "\n";
--EXPECT--
a,b,c
