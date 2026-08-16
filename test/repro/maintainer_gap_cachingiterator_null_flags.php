<?php
/** Maintainer gap: CachingIterator::__construct(null $flags) silent — Zend E_DEPRECATED (ext/spl/spl_iterators.c). */
error_reporting(E_ALL);
ini_set('display_errors', '1');

try {
    $c = new CachingIterator(new ArrayIterator([1]), null);
    echo 'flags=' . $c->getFlags() . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
