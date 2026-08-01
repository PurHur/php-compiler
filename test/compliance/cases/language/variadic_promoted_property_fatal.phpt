--TEST--
Language: variadic promoted property compile fatal (#26515, Zend/zend_compile.c)
--FILE--
<?php
class C {
    public function __construct(public int ...$x) {}
}
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Cannot declare variadic promoted property in %s on line %d
