--TEST--
Language: abstract method parameter $this is compile-time fatal (#32179, Zend/zend_compile.c)
--FILE--
<?php
abstract class C {
    abstract public function m($this);
}
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
%APHP Fatal error:  Cannot use $this as parameter in %s on line %d
