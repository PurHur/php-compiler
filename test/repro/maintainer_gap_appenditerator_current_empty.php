<?php
/** Maintainer gap: AppendIterator::current() when invalid — Zend null, VM RuntimeException (ext/spl/spl_iterators.c). */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$a = new AppendIterator();
echo 'valid=';
var_export($a->valid());
echo "\n";
try {
    $c = $a->current();
    echo 'current=';
    var_export($c);
    echo "\n";
} catch (Throwable $e) {
    echo 'current ', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $k = $a->key();
    echo 'key=';
    var_export($k);
    echo "\n";
} catch (Throwable $e) {
    echo 'key ', get_class($e), ':', $e->getMessage(), "\n";
}
