--TEST--
stdlib ArrayIterator() no-arg constructor (JIT, #11792, spl_array.c)
--FILE--
<?php
declare(strict_types=1);

$it = new ArrayIterator();
$it->append(1);
$it->append(2);
echo count($it), "\n";
--JIT--
--EXPECT--
2
