--TEST--
Language: (object) cast in const expr still rejected under PROFILE=8.5 (#24947)
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
class A {
    public const X = (object) [];
}
echo A::X, "\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Constant expression contains invalid operations
