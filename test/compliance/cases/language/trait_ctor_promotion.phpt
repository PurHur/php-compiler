--TEST--
Language: constructor property promotion in traits (#4671, Zend/zend_traits.c)
--FILE--
<?php
trait HasX {
    public function __construct(public int $x) {}
}
class C {
    use HasX;
}

$c = new C(3);
echo $c->x, "\n";
--EXPECT--
3
