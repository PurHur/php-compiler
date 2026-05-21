--TEST--
stdlib in_array() JIT for scalar haystacks
--FILE--
<?php
$a = array(1, 2, 'x');
echo in_array(2, $a) ? 'y' : 'n', "\n";
echo in_array('2', $a) ? 'y' : 'n', "\n";
echo in_array('2', $a, true) ? 'y' : 'n', "\n";
echo in_array('y', $a) ? 'y' : 'n', "\n";
$routes = array('home', 'contact');
echo in_array('home', $routes, true) ? 'y' : 'n', "\n";
echo in_array('missing', $routes, true) ? 'y' : 'n', "\n";
--EXPECT--
y
y
n
n
y
n
