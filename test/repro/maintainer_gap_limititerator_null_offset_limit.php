<?php
/** Maintainer gap: LimitIterator null offset/limit — TypeError vs Zend soft-null (ext/spl/spl_iterators.c). */
error_reporting(E_ALL);
ini_set('display_errors', '1');

try {
    $li = new LimitIterator(new ArrayIterator([1, 2, 3]), null, null);
    echo 'ok:' . json_encode(iterator_to_array($li)) . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
