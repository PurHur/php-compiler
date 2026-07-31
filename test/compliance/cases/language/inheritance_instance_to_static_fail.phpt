--TEST--
Language: cannot make non-static method static on override (zend_inheritance.c, #25634)
--FILE--
<?php
class A1 { public function f() {} }
class B1 extends A1 { public static function f() {} }
echo "accepted\n";
--EXPECT_EXIT--
255
