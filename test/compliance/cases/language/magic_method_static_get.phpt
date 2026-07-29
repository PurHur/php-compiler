--TEST--
Language: static __get/__set/__isset/__unset/__call/__toString — compile-time fatal (#25027, Zend/zend_compile.c)
--FILE--
<?php
class A { public static function __get($n) { return 1; } }
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Method A::__get() cannot be static in %s on line %d
