<?php
// ArrayObject::exchangeArray(ArrayObject): Zend accepts object|array (php-src spl_array.c).
error_reporting(E_ALL);
try {
    $a = new ArrayObject([0]);
    $a->exchangeArray(new ArrayObject([1, 2]));
    echo json_encode(iterator_to_array($a)), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
