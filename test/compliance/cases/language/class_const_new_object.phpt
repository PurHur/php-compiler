--TEST--
Language: class constant object `new` expression (#10198, Zend/zend_compile.c)
--FILE--
<?php
class C {
    public const X = new stdClass();
}
var_export(C::X);
echo "\n";
echo C::X === C::X ? "1\n" : "0\n";

class Foo {
    public function __construct(public int $x = 0) {}
}

class D {
    public const Y = new Foo(7);
}

var_export(D::Y);
echo "\n";
echo D::Y->x === 7 ? "1\n" : "0\n";
--EXPECT--
(object) array (
)
1
Foo::__set_state(array (
  'x' => 7,
))
1
