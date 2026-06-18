--TEST--
Language: chained assignment expression value ($x = $y = 1) (#6758, #9405, Zend zend_execute.c)
--FILE--
<?php
$x = $y = 1;
var_dump($x, $y);

var_dump($a = $b = 2);
--EXPECT--
int(1)
int(1)
int(2)
