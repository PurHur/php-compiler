--TEST--
Language: parent::method in a free function is compile-time fatal (#32227, Zend/zend_compile.c)
--FILE--
<?php
function f(): void {
    parent::foo();
}
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
%APHP Fatal error:  Cannot use "parent" when no class scope is active in %s on line %d
