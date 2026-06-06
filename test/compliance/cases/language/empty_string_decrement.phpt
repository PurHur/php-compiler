--TEST--
Language: empty string decrement (--/$s--) coerces to int(-1) (#6757, zend_operators.c)
--FILE--
<?php
$s = '';
var_dump($s--);
var_dump($s);

$s = '';
var_dump(--$s);
var_dump($s);
?>
--EXPECT--
string(0) ""
int(-1)
int(-1)
int(-1)
