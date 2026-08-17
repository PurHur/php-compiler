<?php
/** Maintainer gap: CachingIterator::setFlags(null) TypeError — Zend E_DEPRECATED soft coerce (ext/spl/spl_iterators.c). */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$c = new CachingIterator(new ArrayIterator([1]), CachingIterator::FULL_CACHE);
try {
    $c->setFlags(null);
    echo 'flags=' . $c->getFlags() . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
