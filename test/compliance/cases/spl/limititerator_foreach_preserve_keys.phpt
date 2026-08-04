--TEST--
LimitIterator foreach preserves inner keys (#27581)
--FILE--
<?php
$it = new LimitIterator(new ArrayIterator([10, 20, 30, 40]), 1, 2);
foreach ($it as $k => $v) {
    echo "$k:$v ";
}
echo "\n";
--EXPECT--
1:20 2:30 
