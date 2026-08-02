--TEST--
SplFixedArray::fromArray AOT count + dim (#26793)
--FILE--
<?php
$a = SplFixedArray::fromArray([10, 20, 30], false);
echo count($a), '|', $a[2], "\n";
$b = SplFixedArray::fromArray([7, 8], true);
echo count($b), '|', $b->count(), '|', $b[1], "\n";
--EXPECT--
3|30
2|2|8
