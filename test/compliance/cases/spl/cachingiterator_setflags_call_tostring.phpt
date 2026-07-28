--TEST--
CachingIterator::setFlags() cannot clear CALL_TOSTRING (#24252, ext/spl/spl_iterators.c)
--FILE--
<?php
$it = new CachingIterator(new ArrayIterator([1]), CachingIterator::CALL_TOSTRING);
try {
    $it->setFlags(CachingIterator::FULL_CACHE);
    echo "unexpected\n";
} catch (InvalidArgumentException $e) {
    echo $e->getMessage(), "\n";
    echo "flags=", $it->getFlags(), "\n";
}
try {
    $it->setFlags(0);
    echo "unexpected zero\n";
} catch (InvalidArgumentException $e) {
    echo "zero=", $e->getMessage(), "\n";
    echo "flags0=", $it->getFlags(), "\n";
}
$it->setFlags(257); // CALL_TOSTRING|FULL_CACHE
echo "both=", $it->getFlags(), "\n";
?>
--EXPECT--
Unsetting flag CALL_TO_STRING is not possible
flags=1
zero=Unsetting flag CALL_TO_STRING is not possible
flags0=1
both=257
