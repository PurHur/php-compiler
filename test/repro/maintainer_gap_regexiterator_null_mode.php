<?php
/** Maintainer gap: RegexIterator(null $mode) TypeError — Zend E_DEPRECATED soft coerce (ext/spl/spl_iterators.c). */
error_reporting(E_ALL);
ini_set('display_errors', '1');

try {
    $r = new RegexIterator(new ArrayIterator(['a']), '/a/', null);
    echo 'mode=' . $r->getMode() . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
