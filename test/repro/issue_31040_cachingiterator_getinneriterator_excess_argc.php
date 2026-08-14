<?php

/**
 * Repro #31040 — CachingIterator::getInnerIterator() excess argc.
 * php-src: ext/spl/spl_iterators.c — inherited zim_IteratorIterator_getInnerIterator
 */
$it = new CachingIterator(new ArrayIterator([1]));
try {
    echo get_class($it->getInnerIterator(1)), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo 'ok=', get_class($it->getInnerIterator()) === 'ArrayIterator' ? '1' : '0', "\n";
