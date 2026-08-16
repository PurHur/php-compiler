<?php
/** Maintainer gap: RecursiveArrayIterator bad $array TypeError cites ArrayIterator::__construct (ext/spl/spl_array.c). */
error_reporting(E_ALL);
ini_set('display_errors', '1');

foreach ([null, false, 1] as $bad) {
    try {
        new RecursiveArrayIterator($bad);
        echo 'no-throw type=' . gettype($bad) . "\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
