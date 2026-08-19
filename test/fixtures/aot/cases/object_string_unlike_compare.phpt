--TEST--
AOT: object vs string ordered compare / == must verify and match Zend zend_compare (#32515)
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
--EXPECT--
gt
1
rngt
neq
ne
cge
-1
--EXPECT_EXIT--
0
