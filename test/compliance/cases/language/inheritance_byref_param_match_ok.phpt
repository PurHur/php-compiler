--TEST--
Language: matching by-ref param override accepted (zend_inheritance.c, #25633)
--FILE--
<?php
class A { public function f(&$x) {} }
class B extends A { public function f(&$x) {} }
echo "accepted\n";
--EXPECT--
accepted
