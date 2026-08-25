<?php
/**
 * #34745 — AOT by-ref return of nested dims must alias the live HT entry (re-#34740).
 *
 * @see php-src Zend/zend_execute.c ZEND_FETCH_DIM_W / ZEND_RETURN_BY_REF
 */
function &r_idx(&$a)
{
    return $a[0][1];
}

$a = [[1, 2], [3, 4]];
$x = &r_idx($a);
$x = 99;
echo 'idx:';
var_export($a[0][1]);
echo "\n";

function &r_str(&$a)
{
    return $a['o']['k'];
}

$b = ['o' => ['k' => 1]];
$y = &r_str($b);
$y = 99;
echo 'str:';
var_export($b['o']['k']);
echo "\n";
