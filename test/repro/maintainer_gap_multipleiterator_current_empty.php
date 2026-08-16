<?php
/** Maintainer gap: MultipleIterator::current()/key() on empty — Zend RuntimeException (ext/spl/spl_iterators.c). */
error_reporting(E_ALL);
ini_set('display_errors', '1');

foreach (['NEED_ANY' => MultipleIterator::MIT_NEED_ANY, 'NEED_ALL' => MultipleIterator::MIT_NEED_ALL] as $label => $flags) {
    $m = new MultipleIterator($flags);
    echo "flags=$label valid=";
    var_export($m->valid());
    echo "\n";
    try {
        $c = $m->current();
        echo "current=";
        var_export($c);
        echo "\n";
    } catch (Throwable $e) {
        echo 'current ', get_class($e), ':', $e->getMessage(), "\n";
    }
    try {
        $k = $m->key();
        echo "key=";
        var_export($k);
        echo "\n";
    } catch (Throwable $e) {
        echo 'key ', get_class($e), ':', $e->getMessage(), "\n";
    }
}
