--TEST--
Language: final plain property override after ternary still compile-errors under PROFILE=8.4 (#24770, Zend/zend_inheritance.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class A
{
    public final string $x = 'a';
}
echo 'instance_isFinal=', (new ReflectionProperty('A', 'x'))->isFinal() ? '1' : '0', "\n";
class S
{
    public final static string $s = 's';
}
echo 'static_isFinal=', (new ReflectionProperty('S', 's'))->isFinal() ? '1' : '0', "\n";
class B extends A
{
    public string $x = 'b';
}
echo "override_allowed=1\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Cannot override final property A::$x
