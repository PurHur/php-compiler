<?php
// #32766 follow-up — AOT: dynamic string RHS must match Zend (literal assign + function return).
class A
{
}

$o = new A();
var_dump($o instanceof A);
$n = 'A';
var_dump($o instanceof $n);
function cn(): string
{
    return 'A';
}
$m = cn();
var_dump($o instanceof $m);
