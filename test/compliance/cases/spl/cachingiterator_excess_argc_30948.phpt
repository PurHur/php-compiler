--TEST--
CachingIterator getFlags/hasNext/getCache reject extra args (#30948)
--FILE--
<?php
function show($label, $fn) {
    try {
        $fn();
        echo $label, " COERCED\n";
    } catch (Throwable $e) {
        echo $label, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
$c = new CachingIterator(new ArrayIterator([1, 2]));
$c->rewind();
show('getFlags', static fn () => $c->getFlags(1));
show('hasNext', static fn () => $c->hasNext(1));
$c2 = new CachingIterator(new ArrayIterator([1]), CachingIterator::FULL_CACHE);
$c2->rewind();
$c2->next();
show('getCache', static fn () => $c2->getCache(1));
$c3 = new CachingIterator(new ArrayIterator([1]));
show('getCache_nofull', static fn () => $c3->getCache(1));
$r = new RecursiveCachingIterator(new RecursiveArrayIterator([1, [2]]));
show('rci_getFlags', static fn () => $r->getFlags(1));
$fresh = new CachingIterator(new ArrayIterator([1, 2]));
echo 'flags_ok=', $fresh->getFlags(), "\n";
echo 'hasNext_ok=', $c->hasNext() ? '1' : '0', "\n";
$okCache = new CachingIterator(new ArrayIterator([7]), CachingIterator::FULL_CACHE);
foreach ($okCache as $_) {
}
$cache = $okCache->getCache();
echo 'cache_ok=', is_array($cache) && isset($cache[0]) && 7 === $cache[0] ? '1' : '0', "\n";
echo 'rci_flags_ok=', $r->getFlags(), "\n";
?>
--EXPECT--
getFlags ArgumentCountError: CachingIterator::getFlags() expects exactly 0 arguments, 1 given
hasNext ArgumentCountError: CachingIterator::hasNext() expects exactly 0 arguments, 1 given
getCache ArgumentCountError: CachingIterator::getCache() expects exactly 0 arguments, 1 given
getCache_nofull ArgumentCountError: CachingIterator::getCache() expects exactly 0 arguments, 1 given
rci_getFlags ArgumentCountError: CachingIterator::getFlags() expects exactly 0 arguments, 1 given
flags_ok=1
hasNext_ok=1
cache_ok=1
rci_flags_ok=1
