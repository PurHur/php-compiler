--TEST--
AOT: array_shift()/array_pop() variable by-ref still works (#9745, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

$a = [1, 2, 3];
echo 'shift ', array_shift($a), ' count ', count($a), "\n";
$b = [4, 5];
echo 'pop ', array_pop($b), ' count ', count($b), "\n";
--EXPECT--
shift 1 count 2
pop 5 count 1
