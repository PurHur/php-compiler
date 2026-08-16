--TEST--
CachingIterator multi TOSTRING flags → ValueError (#31551, ext/spl/spl_iterators.c)
--FILE--
<?php
error_reporting(E_ALL);
$flags = CachingIterator::CALL_TOSTRING | CachingIterator::TOSTRING_USE_KEY;
try {
    $c = new CachingIterator(new ArrayIterator([1]), $flags);
    echo "construct ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $c = new CachingIterator(new ArrayIterator([1]));
    $c->setFlags($flags);
    echo "setFlags ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
// Single-bit + FULL_CACHE still allowed (construct + setFlags without clearing CALL_TOSTRING)
$ok = new CachingIterator(new ArrayIterator([1]), CachingIterator::CALL_TOSTRING | CachingIterator::FULL_CACHE);
echo "full+call=", $ok->getFlags(), "\n";
$fromFull = new CachingIterator(new ArrayIterator([1]), CachingIterator::FULL_CACHE);
$fromFull->setFlags(CachingIterator::TOSTRING_USE_KEY | CachingIterator::FULL_CACHE);
echo "full+key=", $fromFull->getFlags(), "\n";
?>
--EXPECT--
ValueError: CachingIterator::__construct(): Argument #2 ($flags) must contain only one of CachingIterator::CALL_TOSTRING, CachingIterator::TOSTRING_USE_KEY, CachingIterator::TOSTRING_USE_CURRENT, or CachingIterator::TOSTRING_USE_INNER
ValueError: CachingIterator::setFlags(): Argument #1 ($flags) must contain only one of CachingIterator::CALL_TOSTRING, CachingIterator::TOSTRING_USE_KEY, CachingIterator::TOSTRING_USE_CURRENT, or CachingIterator::TOSTRING_USE_INNER
full+call=257
full+key=258
