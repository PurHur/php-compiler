--TEST--
Language: instance __set_state — compile-time fatal (#25028, Zend/zend_compile.c)
--FILE--
<?php
class A { public function __set_state($a) { return 1; } }
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Method A::__set_state() must be static in %s on line %d
