<?php
/** Maintainer gap: RecursiveIteratorIterator::setMaxDepth(null) → false silent — Zend E_DEPRECATED + maxDepth=0 (ext/spl/spl_iterators.c). */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$r = new RecursiveIteratorIterator(new RecursiveArrayIterator([1]));
try {
    $r->setMaxDepth(null);
    echo 'max=';
    var_export($r->getMaxDepth());
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
