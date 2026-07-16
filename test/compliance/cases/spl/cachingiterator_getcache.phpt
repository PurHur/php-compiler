--TEST--
SPL CachingIterator::getCache() — FULL_CACHE key/value map (#19469, ext/spl/spl_iterators.c)
--FILE--
<?php
$ci = new CachingIterator(new ArrayIterator([1, 2, 3]));
try {
    $ci->getCache();
    echo "no-exception\n";
} catch (BadMethodCallException $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

// Pre-bind iterator + flags: nested `new CachingIterator(new ArrayIterator(...), Class::CONST)`
// currently double-sends the inner New slot (compiler ARG_SEND / #19439 family).
$it = new ArrayIterator([1, 2, 3]);
$flags = CachingIterator::FULL_CACHE;
$ci2 = new CachingIterator($it, $flags);
foreach ($ci2 as $_) {
}
echo implode(',', $ci2->getCache()), "\n";

$it3 = new ArrayIterator(['a' => 10, 'b' => 20]);
$ci3 = new CachingIterator($it3, CachingIterator::FULL_CACHE);
foreach ($ci3 as $_) {
}
$cache = $ci3->getCache();
echo 'a=', $cache['a'], ' b=', $cache['b'], ' count=', count($cache), "\n";
?>
--EXPECT--
BadMethodCallException: CachingIterator does not use a full cache (see CachingIterator::__construct)
1,2,3
a=10 b=20 count=2
