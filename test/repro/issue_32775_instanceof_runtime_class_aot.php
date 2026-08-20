<?php
// #32775 — AOT: instanceof with non-literal / function-returned class string must match Zend.
class A
{
}

function c($o, $n)
{
    var_dump($o instanceof $n);
}

c(new A(), 'A');
c(new A(), 'stdClass');

function name()
{
    return 'A';
}

$o = new A();
$n = name();
var_dump($o instanceof $n);
