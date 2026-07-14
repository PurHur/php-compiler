--TEST--
Language: user implements Closure — runtime fatal (#18781, Zend/zend_inheritance.c)
--FILE--
<?php
echo "before\n";
class C implements Closure {
    public function __invoke(): void {}
}
echo "reach\n";
--EXPECTF--
before

Fatal error: C cannot implement Closure - it is not an interface in %s on line %d
--EXPECT_EXIT--
255
