--TEST--
Language: class constant `new` expression — shared object identity (#10198, Zend/zend_constants.c)
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
--EXPECT--
int(1)
same
