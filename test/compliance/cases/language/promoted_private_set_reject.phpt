--TEST--
Language: promoted constructor public private(set) compile fatal (#13960, Zend/zend_compile.c)
--FILE--
<?php
class D {
    public function __construct(public private(set) int $x = 1) {}
}
$d = new D();
echo $d->x, "\n";
--EXPECT_EXIT--
255
