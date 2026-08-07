--TEST--
AOT: eval() child cannot override outer-unit final plain property under PROFILE=8.4 (#28437)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class A { public final int $x = 1; }
echo 'isFinal=', (new ReflectionProperty(A::class, 'x'))->isFinal() ? '1' : '0', "\n";
eval('class B extends A { public int $x = 2; }');
echo "redef_ok\n";
--EXPECT_COMPILE_FAIL--
Cannot override final property A::$x
