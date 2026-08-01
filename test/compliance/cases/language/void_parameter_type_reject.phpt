--TEST--
Language: standalone void parameter type — compile fatal (#26517, zend_compile.c)
--FILE--
<?php
function acceptsVoid(void $value): void {}
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: void cannot be used as a parameter type
