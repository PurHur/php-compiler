<?php
/**
 * #34740 — AOT by-ref return of $arr[$i] / $arr['k'] must alias the live HT entry (re-#34733).
 *
 * @see php-src Zend/zend_execute.c ZEND_FETCH_DIM_W / ZEND_RETURN_BY_REF
 */
$a = [1, 2, 3];

function &r_global_idx()
{
    global $a;

    return $a[1];
}

$x = &r_global_idx();
$x = 99;
echo 'idx:';
var_export($a);
echo "\n";

$b = ['k' => 1];

function &r_global_str()
{
    global $b;

    return $b['k'];
}

$y = &r_global_str();
$y = 99;
echo 'str:';
var_export($b);
echo "\n";

$c = [1, 2, 3];

function &r_param_idx(&$c)
{
    return $c[1];
}

$z = &r_param_idx($c);
$z = 99;
echo 'param:';
var_export($c);
echo "\n";
