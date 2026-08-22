<?php

// AOT: instance property ??= must store on uninitialized declared props (#33748, re-#32880).
class A33748
{
    public $p;
}

$o = new A33748();
$o->p ??= 5;
echo $o->p, "\n";

class B33748
{
    public $v = 0;
}

$b = new B33748();
$b->v ??= 9;
echo $b->v, "\n";

class C33748
{
    public ?int $v = null;
}

$c = new C33748();
$c->v ??= 3;
echo $c->v, "\n";

$c->v ??= 8;
echo $c->v, "\n";
