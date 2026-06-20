--TEST--
Language: class constant `new` expression with ctor args (#10198, Zend/zend_compile.c)
--FILE--
<?php
class C {
    public function __construct(public int $n = 0) {}
}
class Holder {
    public const X = new C(1);
}
var_dump(Holder::X->n);
--EXPECT--
int(1)
