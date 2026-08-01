--TEST--
Language: void in union parameter type — compile-time fatal (#26517, zend_compile.c)
--FILE--
<?php
function f(int|void $x): void {}
echo "compiled\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Void can only be used as a standalone type
