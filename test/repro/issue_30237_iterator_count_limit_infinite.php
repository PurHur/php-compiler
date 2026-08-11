<?php

// Issue #30237 — iterator_count on LimitIterator(InfiniteIterator) must match Zend (3).
$it = new LimitIterator(new InfiniteIterator(new ArrayIterator([1])), 0, 3);
echo 'iterator_count=', iterator_count($it), "\n";
$it2 = new LimitIterator(new InfiniteIterator(new ArrayIterator([1])), 0, 3);
$n = 0;
foreach ($it2 as $_) {
    ++$n;
}
echo 'foreach=', $n, "\n";
$n = 0;
echo 'apply=', iterator_apply(
    new LimitIterator(new InfiniteIterator(new ArrayIterator([1])), 0, 3),
    static function () use (&$n) {
        ++$n;

        return true;
    }
), "\n";
echo 'apply_cb=', $n, "\n";
