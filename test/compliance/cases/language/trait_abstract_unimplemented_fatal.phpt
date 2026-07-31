--TEST--
Language: trait abstract method unimplemented — DECLARE-time fatal after prior stmts (#25912, Zend/zend_inheritance.c)
--INI--
display_errors=1
--FILE--
<?php
trait TAbs {
    abstract public function f();
}
echo "before\n";
class C {
    use TAbs;
}
echo "accepted\n";
--EXPECTF--
before

Fatal error: Class C contains 1 abstract method and must therefore be declared abstract or implement the remaining methods (C::f) in %s on line %d
--EXPECT_EXIT--
255
