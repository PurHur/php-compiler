--TEST--
Language: method parameter $this is compile-time fatal (#32179, Zend/zend_compile.c)
--FILE--
<?php
class C {
    public function m($this) {}
}
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
%APHP Fatal error:  Cannot use $this as parameter in %s on line %d
