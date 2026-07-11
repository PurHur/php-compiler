--TEST--
stdlib ArrayIterator() no-arg constructor — empty appendable iterator (#11792, spl_array.c)
--FILE--
<?php
declare(strict_types=1);

$it = new ArrayIterator();
$it->append(1);
$it->append(2);
echo count($it), "\n";
--EXPECT--
2
