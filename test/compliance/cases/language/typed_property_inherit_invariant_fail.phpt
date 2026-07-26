--TEST--
Language: typed property inheritance is invariant — stdClass → object fatal (#23505, zend_inheritance.c)
--FILE--
<?php
class A { public stdClass $x; }
eval('class B extends A { public object $x; }');
echo "accepted\n";
--EXPECTF--
PHP Fatal error:  Type of B::$x must be stdClass (as in class A) in %s : eval()'d code on line %d
--EXPECT_EXIT--
255
