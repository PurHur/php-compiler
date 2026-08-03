--TEST--
Language: final plain property override after ternary still compile-errors under PROFILE=8.4 (#24770 / #27122, Zend/zend_inheritance.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class A
{
    public final string $x = 'a';
}
true ? 1 : 0;
echo 'instance_isFinal=', (new ReflectionProperty('A', 'x'))->isFinal() ? '1' : '0', "\n";
class S
{
    public final static string $s = 's';
}
false ? 1 : 0;
echo 'static_isFinal=', (new ReflectionProperty('S', 's'))->isFinal() ? '1' : '0', "\n";
true ? 1 : 0;
class B extends A
{
    public string $x = 'b';
}
echo "override_allowed=1\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Cannot override final property A::$x in %s on line %d
