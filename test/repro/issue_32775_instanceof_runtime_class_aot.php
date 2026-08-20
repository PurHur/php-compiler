<?php
// #32775 — AOT: instanceof with runtime (non-literal) class name string.
class A
{
}
function check($o, $n)
{
    var_dump($o instanceof $n);
}
check(new A(), 'A');
check(new stdClass(), 'stdClass');
// compile-time fold path from #32769 must stay green:
$n = 'A';
var_dump((new A()) instanceof $n);
