--TEST--
AOT iterator_count LimitIterator(InfiniteIterator) nested new (#30273)
--FILE--
<?php
echo 'iterator_count=', iterator_count(
    new LimitIterator(new InfiniteIterator(new ArrayIterator([1])), 0, 3)
), "\n";
--EXPECT--
iterator_count=3
