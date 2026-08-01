--TEST--
Language: promoted property in interface constructor compile fatal (#26529, Zend/zend_compile.c)
--FILE--
<?php
interface I {
    public function __construct(public int $x);
}
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Cannot declare promoted property in an abstract constructor in %s on line %d
