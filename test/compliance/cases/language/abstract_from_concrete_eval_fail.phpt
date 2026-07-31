--TEST--
Language: cannot abstractize concrete parent method via eval (zend_inheritance.c, #25660)
--FILE--
<?php
eval('class A { public function f(): void {} }');
eval('abstract class B extends A { abstract public function f(): void; }');
echo "LOADED\n";
--EXPECTF--
PHP Fatal error:  Cannot make non abstract method A::f() abstract in class B in %s : eval()'d code on line %d
--EXPECT_EXIT--
255
