--TEST--
Language: user implements Closure — compile-time fatal (#15445, Zend/zend_inheritance.c)
--FILE--
<?php
class C implements Closure {
    public function __invoke(): void {}
}
echo "reach\n";
--EXPECT_EXIT--
255
