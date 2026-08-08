<?php

declare(strict_types=1);

/**
 * Nested FuncCall as call arg with sibling inline Array_ literal (#28891, re-#16013).
 *
 * Zend: true / 0 / ['a','b'] / true
 * Broken VM: false / argc error or false / ['a','a'] / true (control)
 */
function id(mixed $x): mixed
{
    return $x;
}

var_export(in_array(id('x'), ['x'], true));
echo "\n";
var_export(array_search(id('x'), ['x'], true));
echo "\n";
var_export(array_merge(['a'], id(['b'])));
echo "\n";
$hay = ['x'];
var_export(in_array(id('x'), $hay, true));
echo "\n";