<?php
/**
 * AOT foreach + $x[] = $v must keep every Generator yield (#24145).
 * Zend/VM: 1,2 — AOT previously overwrote index 0 (baked nextFreeElement).
 */
function g()
{
    yield 1;
    yield 2;
}
$x = [];
foreach (g() as $v) {
    $x[] = $v;
}
echo implode(',', $x), "\n";
