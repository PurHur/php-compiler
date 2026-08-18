--TEST--
Language: self::method in a free function is compile-time fatal (#32227, Zend/zend_compile.c)
--FILE--
<?php
function f(): void {
    self::foo();
}
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
%APHP Fatal error:  Cannot use "self" when no class scope is active in %s on line %d
