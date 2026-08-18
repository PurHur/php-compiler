--TEST--
Language: new static in a free function is compile-time fatal (#32252, Zend/zend_compile.c)
--FILE--
<?php
function f() {
    return new static;
}
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
%APHP Fatal error:  Cannot use "static" when no class scope is active in %s on line %d
