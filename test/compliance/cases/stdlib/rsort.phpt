--TEST--
stdlib rsort() on string list arrays (#2300)
--FILE--
<?php
$routes = ['b', 'a', 'c'];
rsort($routes);
echo implode(',', $routes), "\n";
--EXPECT--
c,b,a
