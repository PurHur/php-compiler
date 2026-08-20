<?php
// #32766 — $obj instanceof $className (runtime string) must compile under thin AOT.
class A
{
}

$o = new A();
$n = 'A';
var_dump($o instanceof $n);
var_dump($o instanceof A);
