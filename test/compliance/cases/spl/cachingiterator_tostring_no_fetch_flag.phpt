--TEST--
CachingIterator string cast without CALL_TOSTRING throws BadMethodCallException (#24907)
--FILE--
<?php
$it = new CachingIterator(new ArrayIterator(['a' => 1, 'b' => 2]), CachingIterator::FULL_CACHE);
foreach ($it as $k => $v) {
}
echo 'cache_a=', $it->getCache()['a'], "\n";
try {
    echo 'str=', (string) $it, "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

$keyIt = new CachingIterator(new ArrayIterator(['a' => 1]), CachingIterator::TOSTRING_USE_KEY);
$keyIt->rewind();
echo 'key=', (string) $keyIt, "\n";

$curIt = new CachingIterator(new ArrayIterator(['a' => 1]), CachingIterator::TOSTRING_USE_CURRENT);
$curIt->rewind();
echo 'cur=', (string) $curIt, "\n";
?>
--EXPECT--
cache_a=1
str=BadMethodCallException:CachingIterator does not fetch string value (see CachingIterator::__construct)
key=a
cur=1
