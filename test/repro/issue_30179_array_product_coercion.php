<?php
// Issue #30179 — array_product() must coerce string-numeric and bool elements like Zend.
// php-src: ext/standard/array.c zval_get_long / zval_get_double

$result = array_product([2, 3, "2a", true, 4.0]);
if ($result != 48) {
    echo "FAIL: array_product coercion expected 48, got {$result}\n";
    exit(1);
}

echo "OK\n";
