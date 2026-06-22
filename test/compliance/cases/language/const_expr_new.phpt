--TEST--
Language: class constant `new` expression object identity rejected (#10391, Zend/zend_constants.c)
--FILE--
<?php
class C {
    public function __construct(public int $n = 0) {}
}
class Holder {
    public const X = new C(1);
}
var_dump(Holder::X->n);
echo Holder::X === Holder::X ? "same\n" : "diff\n";
--EXPECT_EXIT--
255
