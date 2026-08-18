--TEST--
Language: float ++/-- stays float (zend increment_function IS_DOUBLE, #32281)
--FILE--
<?php
$x = 1.5;
$old = $x++;
var_dump($old, $x);
$y = 1.5;
++$y;
var_dump($y);
$z = 2.5;
$z--;
var_dump($z);
?>
--EXPECT--
float(1.5)
float(2.5)
float(2.5)
float(1.5)
