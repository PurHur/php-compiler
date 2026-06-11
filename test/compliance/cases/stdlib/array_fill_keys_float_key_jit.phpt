--TEST--
JIT: array_fill_keys() — float keys stringify like Zend (#4307)
--FILE--
<?php
$a = array_fill_keys(array(1.5), 'v');
echo $a['1.5'], "\n";
$b = array_fill_keys(array(2.0), 'w');
echo $b[2], "\n";
--EXPECT--
v
w
