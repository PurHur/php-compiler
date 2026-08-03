--TEST--
Language: final promoted property cannot be overridden (#22451, #27123, Zend/zend_inheritance.c)
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
class ParentFP {
    public function __construct(public final string $name) {}
}
class ChildFP extends ParentFP {
    public function __construct(public string $name) {}
}
echo "override_ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Cannot override final property ParentFP::$name in %s on line %d
