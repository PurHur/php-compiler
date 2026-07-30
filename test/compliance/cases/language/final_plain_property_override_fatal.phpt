--TEST--
Language: cannot override final plain property (#22241, Zend/zend_inheritance.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class ParentF {
    final public string $name = 'a';
}
class ChildF extends ParentF {
    public string $name = 'b';
}
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Cannot override final property ParentF::$name in %s on line %d
