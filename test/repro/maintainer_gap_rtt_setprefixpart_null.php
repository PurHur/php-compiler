<?php
/** Maintainer gap: RecursiveTreeIterator::setPrefixPart(null) TypeError — Zend E_DEPRECATED soft coerce (ext/spl/spl_iterators.c). */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$t = new RecursiveTreeIterator(new RecursiveArrayIterator([1]));
try {
    $t->setPrefixPart(null, 'X');
    echo 'prefix=' . $t->getPrefix() . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
