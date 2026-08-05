--TEST--
Language: child override of trait-imported final plain property is compile error under PROFILE=8.4 (#27818, Zend/zend_inheritance.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
trait T
{
    public final string $x = 't';
}
class ParentFinal
{
    use T;
}
class Child extends ParentFinal
{
    public string $x = 'b';
}
echo "override=ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Cannot override final property ParentFinal::$x in %s on line %d
