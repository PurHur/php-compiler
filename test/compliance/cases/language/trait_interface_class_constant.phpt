--TEST--
trait and interface class constants resolve on composing class and interface (#9430, zend_constants.c)
--FILE--
<?php
trait T {
    public const C = 1;
}
class C {
    use T;
}
interface I {
    public const X = 2;
}
class D implements I {}
var_dump(C::C);
var_dump(D::X);
var_dump(I::X);
--EXPECT--
int(1)
int(2)
int(2)
