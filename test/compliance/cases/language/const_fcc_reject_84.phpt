--TEST--
Language: FCC in class/enum/file const rejected under PROFILE≤8.4 (#31167, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C { public const X = strlen(...); }
$f = C::X;
echo $f('ab'), "\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Constant expression contains invalid operations
