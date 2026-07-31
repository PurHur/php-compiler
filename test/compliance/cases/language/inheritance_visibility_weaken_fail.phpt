--TEST--
Language: cannot weaken method visibility on override (zend_inheritance.c, #25634)
--FILE--
<?php
class A2 { public function f() {} }
class B2 extends A2 { protected function f() {} }
echo "accepted\n";
--EXPECT_EXIT--
255
