--TEST--
Language: PHP_INT_MIN % -1 is 0 (zend_operators.c mod_function, remaining #31968)
--FILE--
<?php
var_dump(PHP_INT_MIN % -1);
$a = PHP_INT_MIN;
$b = -1;
var_dump($a % $b);
var_dump($a % -1.0);
var_dump(7 % 3);
?>
--EXPECT--
int(0)
int(0)
int(0)
int(1)
