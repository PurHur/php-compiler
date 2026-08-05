--TEST--
CachingIterator FULL_CACHE AOT foreach + getCache (#27421)
--FILE--
<?php
$it = new CachingIterator(new ArrayIterator(['a', 'b']), CachingIterator::FULL_CACHE);
foreach ($it as $v) {
    echo $v;
}
echo "\n", count($it->getCache()), "\n";
--EXPECT--
ab
2
