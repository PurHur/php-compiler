<?php
/**
 * CachingIterator without CALL_TOSTRING / TOSTRING_USE_* must throw on string cast (#24907).
 *
 *   php test/repro/issue_24907_cachingiterator_tostring.php
 *   php bin/vm.php test/repro/issue_24907_cachingiterator_tostring.php
 */
$it = new CachingIterator(new ArrayIterator(['a' => 1, 'b' => 2]), CachingIterator::FULL_CACHE);
foreach ($it as $k => $v) {
}
echo 'cache_a=', $it->getCache()['a'], "\n";
try {
    $s = (string) $it;
    echo 'CAST_OK:', var_export($s, true), "\n";
} catch (Throwable $e) {
    echo 'CAST_THROW:', get_class($e), '|', $e->getMessage(), "\n";
}

$keyIt = new CachingIterator(new ArrayIterator(['a' => 1, 'b' => 2]), CachingIterator::TOSTRING_USE_KEY);
$keyIt->rewind();
echo 'KEY_REWIND=', var_export((string) $keyIt, true), "\n";
