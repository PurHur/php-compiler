<?php
/**
 * #28038 — function static array dim/append/unset must persist; call args must read the CV.
 * Zend/zend_execute.c BIND_STATIC + FETCH_DIM_W.
 */
function f_append(): string
{
    static $x = [1, 2, 3];
    $x[] = 4;
    return implode(',', $x);
}
function f_unset(): string
{
    static $x = ['a' => 1, 'b' => 2];
    unset($x['a']);
    return json_encode($x);
}
function f_shift(): string
{
    static $x = [1, 2, 3];
    $v = array_shift($x);
    return $v . ':' . implode(',', $x);
}
echo f_append(), "\n", f_append(), "\n";
echo f_unset(), "\n", f_unset(), "\n";
echo f_shift(), "\n", f_shift(), "\n";
