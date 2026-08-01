--TEST--
Language: void in union return type — compile-time fatal (#26517, zend_compile.c)
--FILE--
<?php
function f(): int|void {}
echo "compiled\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Void can only be used as a standalone type
