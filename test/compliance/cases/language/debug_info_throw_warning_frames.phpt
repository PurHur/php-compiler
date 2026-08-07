--TEST--
Language: __debugInfo throw Warning includes hook + var_dump frames (#28618, Zend/zend.c)
--FILE--
<?php
class C {
    public function __debugInfo() { throw new RuntimeException('x'); }
}
var_dump(new C());
echo "after\n";
--EXPECTF--
PHP Warning:  Uncaught RuntimeException: x in %s:%d
Stack trace:
#0 [internal function]: C->__debugInfo()
#1 %s(%d): var_dump()
#2 {main}
  thrown in %s on line %d
PHP Fatal error:  __debuginfo() must return an array in %s on line %d
--EXPECT_EXIT--
255
