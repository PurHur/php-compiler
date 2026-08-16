<?php
// CachingIterator: mutually exclusive TOSTRING flags → ValueError (php-src spl_iterators.c).
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
