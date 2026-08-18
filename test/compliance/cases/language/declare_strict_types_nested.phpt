--TEST--
Language: declare(strict_types=1) inside a function is compile-time fatal (#32182, Zend/zend_compile.c)
--FILE--
<?php
function foo() {
    declare(strict_types=1);
}
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
%APHP Fatal error:  strict_types declaration must be the very first statement in the script in %s on line %d
