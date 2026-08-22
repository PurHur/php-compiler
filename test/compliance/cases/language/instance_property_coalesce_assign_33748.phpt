--TEST--
Language: ??= on uninitialized instance property stores and readback matches Zend (#33748)
--FILE--
<?php
error_reporting(E_ALL);

class C33748
{
    public $p;
}

$o = new C33748();
$o->p ??= 5;
var_dump($o->p);
$o->p ??= 9;
var_dump($o->p);

class N33748
{
    public $p = null;
}

$n = new N33748();
$n->p ??= 7;
var_dump($n->p);

class S33748
{
    public $p = 3;
}

$s = new S33748();
$s->p ??= 8;
var_dump($s->p);
?>
--EXPECT--
int(5)
int(5)
int(7)
int(3)
