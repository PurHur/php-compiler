--TEST--
Language: new self in a free function is compile-time fatal (#32252, Zend/zend_compile.c)
--FILE--
<?php
function f() {
    return new self;
}
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
%APHP Fatal error:  Cannot use "self" when no class scope is active in %s on line %d
