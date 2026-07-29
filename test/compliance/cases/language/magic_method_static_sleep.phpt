--TEST--
Language: static __sleep/__wakeup/__invoke — compile-time fatal (#25026, Zend/zend_compile.c)
--FILE--
<?php
class Sl { static function __sleep() { return []; } }
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Method Sl::__sleep() cannot be static in %s on line %d
