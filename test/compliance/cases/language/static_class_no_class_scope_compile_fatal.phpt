--TEST--
Language: static::class in a free function is compile-time fatal with Zend wording (#32227, Zend/zend_compile.c)
--FILE--
<?php
function f(): void {
    echo static::class;
}
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
%APHP Fatal error:  Cannot use "static" when no class scope is active in %s on line %d
