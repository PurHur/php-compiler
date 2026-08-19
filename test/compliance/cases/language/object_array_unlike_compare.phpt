--TEST--
Language: object/array vs scalar < > <=> matches zend_compare (#32503, Zend/zend_operators.c)
--FILE--
<?php
error_reporting(E_ALL & ~E_NOTICE);
class C32503 {}
echo (new stdClass() > 1) ? "gt\n" : "ngt\n";
echo (new stdClass() <=> 1), "\n";
echo (1 > new stdClass()) ? "rgt\n" : "rngt\n";
echo ([1] > 1) ? "agt\n" : "angt\n";
echo ([1] <=> 1), "\n";
echo (new C32503() >= 1) ? "cge\n" : "ncge\n";
?>
--EXPECT--
ngt
0
rngt
agt
1
cge
