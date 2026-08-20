<?php

// AOT: instance property ??= must compile and match Zend (#32880).
class A
{
    public $v;
}

class B
{
    public $v = 0;
}

class C
{
    public ?int $v = null;
}

$a = new A();
$a->v ??= 7;
var_dump($a->v);

$b = new B();
$b->v ??= 9;
var_dump($b->v);

$c = new C();
$c->v ??= 3;
var_dump($c->v);
