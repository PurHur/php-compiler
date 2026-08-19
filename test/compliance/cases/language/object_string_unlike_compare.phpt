--TEST--
Language: object vs string < > <=> == matches zend_compare (#32515 leftover of #32503, Zend/zend_operators.c)
--FILE--
<?php
class C32515 {}
echo (new stdClass() > "a") ? "gt\n" : "ngt\n";
echo (new stdClass() <=> "a"), "\n";
echo ("a" > new stdClass()) ? "rgt\n" : "rngt\n";
echo (new stdClass() == "a") ? "eq\n" : "neq\n";
echo (new stdClass() != "a") ? "ne\n" : "nne\n";
echo (new C32515() >= "z") ? "cge\n" : "ncge\n";
echo ("z" <=> new stdClass()), "\n";
?>
--EXPECT--
gt
1
rngt
neq
ne
cge
-1
