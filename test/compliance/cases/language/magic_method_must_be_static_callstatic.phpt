--TEST--
Language: instance __callStatic — compile-time fatal (#25028, Zend/zend_compile.c)
--FILE--
<?php
class A { public function __callStatic($n, $a) { return 1; } }
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Method A::__callStatic() must be static in %s on line %d
