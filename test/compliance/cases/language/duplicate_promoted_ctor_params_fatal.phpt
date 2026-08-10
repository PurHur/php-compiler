--TEST--
Language: duplicate promoted constructor parameters — Redefinition of parameter (#29979, Zend/zend_compile.c)
--FILE--
<?php
class A {
    public function __construct(public readonly int $a, public int $a) {}
}
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
PHP Fatal error:  Redefinition of parameter $a in %s on line %d
