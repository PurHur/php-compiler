--TEST--
stdlib array_reduce() empty without initial returns null
--FILE--
<?php
function sum(int $carry, int $item): int
{
    return $carry + $item;
}
var_export(array_reduce(array(), 'sum'));
--EXPECT--
NULL
