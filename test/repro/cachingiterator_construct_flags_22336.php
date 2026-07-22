<?php
/**
 * Repro #22336 — CachingIterator default flags=CALL_TOSTRING; explicit null → 0.
 * php-src: ext/spl/spl_iterators.c / spl.stub.php
 */
$c = new CachingIterator(new ArrayIterator([1]));
echo "default=", $c->getFlags(), "\n";
try {
    $c2 = new CachingIterator(new ArrayIterator([1]), null);
    echo "null_ok flags=", $c2->getFlags(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ":", $e->getMessage(), "\n";
}
