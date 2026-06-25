--TEST--
Language: promoted constructor public private(set) compile fatal (#11656, Zend/zend_compile.c)
--FILE--
<?php
class D {
    public function __construct(public private(set) int $x = 1) {}
}
echo (new D())->x, "\n";
--EXPECT_EXIT--
255
