<?php
/** Maintainer gap: LimitIterator::seek(null) TypeError — Zend E_DEPRECATED soft coerce (ext/spl/spl_iterators.c). */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$l = new LimitIterator(new ArrayIterator([10, 20, 30]), 0, 3);
$l->rewind();
try {
    $l->seek(null);
    echo 'cur=' . $l->current() . ' key=' . $l->key() . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
