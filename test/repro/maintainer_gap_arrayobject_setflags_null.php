<?php
/** Maintainer gap: ArrayObject/ArrayIterator::setFlags(null) silent — Zend E_DEPRECATED (ext/spl/spl_array.c). */
error_reporting(E_ALL);
ini_set('display_errors', '1');

foreach (['ArrayObject' => new ArrayObject([1]), 'ArrayIterator' => new ArrayIterator([1])] as $label => $a) {
    echo "== $label ==\n";
    try {
        $a->setFlags(null);
        echo 'flags=' . $a->getFlags() . "\n";
    } catch (Throwable $e) {
        echo get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}
