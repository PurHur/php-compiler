--TEST--
Language: new in class constant initializer rejected (#9484, #9517, Zend/zend_compile.c)
--FILE--
<?php
class C {
    public function __construct(public int $n = 0) {}
}
class Holder {
    public const X = new C(1);
}
var_dump(Holder::X->n);
--EXPECT_EXIT--
255
