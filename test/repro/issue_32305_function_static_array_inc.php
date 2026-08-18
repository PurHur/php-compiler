<?php
/**
 * #32305 / #31968 group 3 — function-static array dim ++ persists across calls.
 * AOT: stale StringTriggerErrorJit FQCN compile-fatal, then FETCH_DIM_W lvalue
 * was a detached box so ++ never wrote the packed hashtable (zend_vm_def.h).
 */
function f()
{
    static $a = [1];
    $a[0]++;
    echo $a[0];
}
f();
echo '|';
f();
echo "\n";
$b = [4];
$b[0]++;
echo $b[0], "\n";
