--TEST--
Intersection parameter call with builtin interfaces (Countable&Traversable, #10899)
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
