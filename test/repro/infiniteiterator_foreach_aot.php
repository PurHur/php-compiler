<?php
// Issue #27568 — InfiniteIterator foreach under AOT.
$it = new InfiniteIterator(new ArrayIterator([1, 2]));
$n = 0;
$out = [];
foreach ($it as $v) {
    $out[] = $v;
    if (++$n >= 5) {
        break;
    }
}
echo implode(',', $out), PHP_EOL;
