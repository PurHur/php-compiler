--TEST--
stdlib rsort() JIT string list (#2300)
--FILE--
<?php
$routes = array('b', 'a', 'c');
rsort($routes);
echo implode(',', $routes), "\n";
--EXPECT--
c,b,a
