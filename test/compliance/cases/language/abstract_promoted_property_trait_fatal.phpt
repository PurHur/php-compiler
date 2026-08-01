--TEST--
Language: promoted property in abstract trait constructor compile fatal (#26529, Zend/zend_compile.c)
--FILE--
<?php
trait T {
    abstract public function __construct(public int $x);
}
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Cannot declare promoted property in an abstract constructor in %s on line %d
