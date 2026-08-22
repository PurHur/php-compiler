--TEST--
AOT: ??= on uninitialized instance property stores; readback matches Zend (#33748)
--FILE--
<?php
class A33748Aot
{
    public $p;
}

$o = new A33748Aot();
$o->p ??= 5;
var_dump($o->p);
$o->p ??= 9;
var_dump($o->p);

class C33748Aot
{
    public ?int $v = null;
}

$c = new C33748Aot();
$c->v ??= 3;
var_dump($c->v);
?>
--EXPECT--
int(5)
int(5)
int(3)
--EXPECT_EXIT--
0
