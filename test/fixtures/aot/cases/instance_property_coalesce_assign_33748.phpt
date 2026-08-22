--TEST--
AOT: ??= on uninitialized instance property stores; readback matches Zend (#33748)
--FILE--
<?php
class C33748Aot
{
    public $p;
}

$o = new C33748Aot();
$o->p ??= 5;
var_dump($o->p);
$o->p ??= 9;
var_dump($o->p);

class N33748Aot
{
    public $p = null;
}

$n = new N33748Aot();
$n->p ??= 7;
var_dump($n->p);

class S33748Aot
{
    public $p = 3;
}

$s = new S33748Aot();
$s->p ??= 8;
var_dump($s->p);
?>
--EXPECT--
int(5)
int(5)
int(7)
int(3)
--EXPECT_EXIT--
0
