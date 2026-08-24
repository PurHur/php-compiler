--TEST--
CachingIterator::getCache without FULL_CACHE throws BadMethodCallException (#34490)
--FILE--
<?php
$it = new CachingIterator(new ArrayIterator([1, 2]));
foreach ($it as $v) {
    echo $v;
}
try {
    $it->getCache();
    echo "NO_THROW\n";
} catch (BadMethodCallException $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
12CachingIterator does not use a full cache (see CachingIterator::__construct)
