<?php
// ArrayObject::__construct(ArrayObject): Zend accepts object|array (php-src spl_array.c).
error_reporting(E_ALL);
try {
    $b = new ArrayObject([1, 2]);
    $a = new ArrayObject($b);
    echo json_encode(iterator_to_array($a)), "\n";
    $b[0] = 99;
    echo json_encode(iterator_to_array($a)), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
