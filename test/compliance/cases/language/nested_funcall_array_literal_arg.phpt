--TEST--
Regression: nested FuncCall arg with sibling Array_ literal — in_array/array_search/array_merge (#28891, re-#16013)
--FILE--
<?php
declare(strict_types=1);

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
--EXPECT--
true
0
array (
  0 => 'a',
  1 => 'b',
)
true