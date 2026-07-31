--TEST--
Language: by-ref param added on override rejected (zend_inheritance.c, #25633)
--FILE--
<?php
class A { public function f($x) {} }
class B extends A { public function f(&$x) {} }
echo "accepted\n";
--EXPECT_EXIT--
255
