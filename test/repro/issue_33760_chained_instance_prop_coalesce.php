<?php

// AOT: chained instance ??= must verify + store like Zend (#33760, re-#33748).
class A33760
{
    public $p;
}

class B33760
{
    public $q;
}

$a = new A33760();
$b = new B33760();
$a->p ??= $b->q ??= 9;
echo $a->p, "\n";
echo $b->q, "\n";

$a->p ??= $b->q ??= 1;
echo $a->p, "\n";
echo $b->q, "\n";
