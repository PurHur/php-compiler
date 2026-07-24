<?php
/** Issue #22770 — new C(null, [...]) must keep null on arg #1 (Zend/zend_compile.c). */
class C
{
    public function __construct($a, $b)
    {
        echo 'a=', var_export($a, true), ' b=', gettype($b), "\n";
    }
}
new C(null, ['k' => 1]);
$n = null;
new C($n, ['k' => 1]);
function f($a, $b)
{
    echo 'f a=', var_export($a, true), ' b=', gettype($b), "\n";
}
f(null, ['k' => 1]);
