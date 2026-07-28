<?php
$it = new CachingIterator(new ArrayIterator([1]), CachingIterator::CALL_TOSTRING);
try {
    $it->setFlags(CachingIterator::FULL_CACHE);
    echo "unexpected ok flags=", $it->getFlags(), "\n";
} catch (InvalidArgumentException $e) {
    echo "ex=", $e->getMessage(), " flags=", $it->getFlags(), "\n";
}
try {
    $it->setFlags(0);
    echo "unexpected zero ok flags=", $it->getFlags(), "\n";
} catch (InvalidArgumentException $e) {
    echo "ex0=", $e->getMessage(), " flags=", $it->getFlags(), "\n";
}
$it->setFlags(257); // CALL_TOSTRING | FULL_CACHE
echo "both=", $it->getFlags(), "\n";
