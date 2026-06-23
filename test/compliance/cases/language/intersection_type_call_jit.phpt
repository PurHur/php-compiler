--TEST--
Intersection parameter call with builtin interfaces JIT (Countable&Traversable, #10899)
--JIT--
--FILE--
<?php
declare(strict_types=1);

function ic(Countable&Traversable $x): int
{
    return count($x);
}

echo ic(new ArrayIterator([1, 2, 3])), "\n";
?>
--EXPECT--
3
