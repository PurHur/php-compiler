<?php
// #32766 — AOT: $obj instanceof $className must match Zend (dynamic string RHS).
class A
{
}

$o = new A();
var_dump($o instanceof A);
$n = 'A';
var_dump($o instanceof $n);
