<?php
// #34524 — typed :bool return with untyped operand must compile and match Zend.
function f($x): bool
{
    return $x % 2 == 0;
}
var_dump(f(2), f(1));
