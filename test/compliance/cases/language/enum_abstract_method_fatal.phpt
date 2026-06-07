--TEST--
Language: enum abstract methods — Zend compile fatal message (#6835, Zend/zend_compile.c)
--FILE--
<?php
enum E {
    abstract public function f(): void;
    case A;
}
echo "compiled\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Enum E must implement 1 abstract private method (E::f) in %s on line %d
