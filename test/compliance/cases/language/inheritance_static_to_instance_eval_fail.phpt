--TEST--
Language: static→instance rejected via eval inherit (zend_inheritance.c, #25634)
--FILE--
<?php
eval('class A1 { public static function f() {} }');
eval('class B1 extends A1 { public function f() {} }');
echo "accepted\n";
--EXPECTF--
PHP Fatal error:  Cannot make static method A1::f() non static in class B1 in %s : eval()'d code on line %d
--EXPECT_EXIT--
255
