--TEST--
Language: __set_state wrong param type — compile-time fatal (#26501, Zend/zend_compile.c)
--FILE--
<?php
class C { static function __set_state(int $a): object { return new C; } }
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: C::__set_state(): Parameter #1 ($a) must be of type array when declared in %s on line %d
