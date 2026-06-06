--TEST--
Language: positional argument after named — compile-time Error (#4299, zend_compile.c)
--FILE--
<?php
function f(int $a, int $b = 0): void {}
f(a: 1, 2);
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Cannot use positional argument after named argument
