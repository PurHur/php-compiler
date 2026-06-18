--TEST--
Language: new in class constant initializer with ctor parens (#9431, PHP 8.3, Zend/zend_compile.c)
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
