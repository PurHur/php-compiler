<?php
/**
 * #35213 — AOT boxed float == native int must match Zend.
 *
 * Prior: looseEqualValueToNativeLong fell through to false for TYPE_NATIVE_DOUBLE
 * so `$x = 1.0; $x == 1` (and untyped f(1.0)==1) were always false.
 *
 *   php bin/vm.php test/repro/issue_35213_boxed_float_eq_int.php
 *   php bin/compile.php -o /tmp/feqi.bin test/repro/issue_35213_boxed_float_eq_int.php && /tmp/feqi.bin
 */
$x = 1.0;
var_dump($x == 1);
var_dump(1 == $x);
var_dump($x != 1);
var_dump($x == 2);
function f($v)
{
    return $v;
}
var_dump(f(1.0) == 1);
var_dump(1 == f(1.0));
var_dump(f(1.5) == 1);
var_dump(f(0.0) == 0);
