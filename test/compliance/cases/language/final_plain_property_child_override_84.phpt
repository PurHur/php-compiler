--TEST--
Language: child override of final plain property is compile error under PROFILE=8.4 (#24687, re-#23824, Zend/zend_inheritance.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
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
