<?php
/** Maintainer gap: RecursiveIteratorIterator(null mode) missing E_DEPRECATED (ext/spl/spl_iterators.c). */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$r = new RecursiveIteratorIterator(new RecursiveArrayIterator([1, [2]]), null);
echo json_encode(iterator_to_array($r)) . "\n";
