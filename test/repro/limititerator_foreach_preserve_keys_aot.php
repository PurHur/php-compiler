<?php
// Issue #27581 — LimitIterator foreach must keep inner keys under AOT.
$it = new LimitIterator(new ArrayIterator([10, 20, 30, 40]), 1, 2);
foreach ($it as $k => $v) {
    echo "$k:$v ";
}
echo "\n";
