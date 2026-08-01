--TEST--
Language: child cannot override parent final plain property under PROFILE=8.5 (#26306, Zend/zend_inheritance.c)
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
class ParentFinal
{
    public final string $x = 'a';
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
