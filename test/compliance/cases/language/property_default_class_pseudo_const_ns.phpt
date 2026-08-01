--TEST--
Language: namespaced property defaults fold self/parent/Named::class (#26629, zend_compile.c)
--FILE--
<?php
namespace Foo;
class B {}
class A extends B {
    public $x = self::class;
    public $y = parent::class;
    public $z = B::class;
    public $w = \Foo\B::class;
}
$a = new A;
echo $a->x, "|", $a->y, "|", $a->z, "|", $a->w, "\n";
--EXPECT--
Foo\A|Foo\B|Foo\B|Foo\B
