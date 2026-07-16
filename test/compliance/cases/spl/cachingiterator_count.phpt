--TEST--
SPL CachingIterator::count() — BadMethodCallException without FULL_CACHE (#13379, ext/spl/spl_iterators.c)
--FILE--
<?php
$it = new ArrayIterator([1, 2, 3]);
$ci = new CachingIterator($it);
try {
    $ci->count();
    echo "no-exception\n";
} catch (BadMethodCallException $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

$it2 = new ArrayIterator([1, 2, 3]);
$flags = CachingIterator::FULL_CACHE;
$ci2 = new CachingIterator($it2, $flags);
foreach ($ci2 as $_) {
}
echo 'full_cache_count=', $ci2->count(), "\n";
?>
--EXPECT--
BadMethodCallException: CachingIterator does not use a full cache (see CachingIterator::__construct)
full_cache_count=3
