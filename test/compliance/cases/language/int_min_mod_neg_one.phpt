--TEST--
Language: PHP_INT_MIN % -1 is 0 (zend mod_function, #32285)
--FILE--
<?php
var_dump(PHP_INT_MIN % -1);
$a = PHP_INT_MIN;
$b = -1;
var_dump($a % $b);
var_dump(7 % -1);
?>
--EXPECT--
int(0)
int(0)
int(0)
