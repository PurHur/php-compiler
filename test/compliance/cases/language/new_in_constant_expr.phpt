--TEST--
Language: new in class constant initializer must compile-error (#9373, Zend/zend_compile.c)
--FILE--
<?php
class C {
    public function __construct(public int $n = 0) {}
}
class Holder {
    public const X = new C(1);
}
--EXPECT_EXIT--
255
