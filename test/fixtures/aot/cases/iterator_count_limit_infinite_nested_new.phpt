--TEST--
AOT iterator_count LimitIterator(InfiniteIterator) nested new (#30273)
--FILE--
<?php
echo 'iterator_count=', iterator_count(
    new LimitIterator(new InfiniteIterator(new ArrayIterator([1])), 0, 3)
), "\n";
$n = 0;
foreach (new LimitIterator(new InfiniteIterator(new ArrayIterator([1])), 0, 3) as $_) {
    ++$n;
}
echo 'foreach=', $n, "\n";
echo 'apply=', iterator_apply(
    new LimitIterator(new InfiniteIterator(new ArrayIterator([1])), 0, 3),
    static function () {
        return true;
    }
), "\n";
--EXPECT--
iterator_count=3
foreach=3
apply=3
