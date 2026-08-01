--TEST--
Language: __unserialize wrong param type — compile-time fatal (#26501, Zend/zend_compile.c)
--FILE--
<?php
class C { function __unserialize(string $data): void {} }
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: C::__unserialize(): Parameter #1 ($data) must be of type array when declared in %s on line %d
