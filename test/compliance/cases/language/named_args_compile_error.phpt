--TEST--
Language: duplicate named parameter — compile-time Error (#4299, zend_compile.c)
--FILE--
<?php
function f(int $a, int $b = 0): void {}
f(a: 1, a: 2);
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Named parameter $a overwrites previous argument
