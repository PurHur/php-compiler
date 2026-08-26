<?php
/**
 * #35220 — AOT boxed value == / != native bool must match Zend (zend_is_true).
 *
 * Prior: Helper VALUE⊙NATIVE_BOOL TYPE_EQUAL fell through → compile abort pair 134/2.
 * Also: boxed numeric string `$x="1"; $x==1` was always false (no string arm).
 *
 *   php bin/vm.php test/repro/issue_35220_boxed_value_eq_bool.php
 *   php bin/compile.php -o /tmp/veqb.bin test/repro/issue_35220_boxed_value_eq_bool.php && /tmp/veqb.bin
 */
$x = 1.0;
var_dump($x == true);
var_dump(true == $x);
var_dump($x != true);
$y = 0.0;
var_dump($y == false);
var_dump(false == $y);
$i = 1;
var_dump($i == true);
$s = '1';
var_dump($s == 1);
var_dump($s == true);
$z = '0';
var_dump($z == false);
var_dump($z == 0);
function f($v)
{
    return $v;
}
var_dump(f(1.0) == true);
var_dump(f(0.0) == false);
$empty = '';
var_dump($empty == false);
var_dump($empty == 0);
