<?php
// Issue #30178 — array_sum() must coerce string-numeric and bool elements like Zend.
// php-src: ext/standard/array.c zval_get_long / zval_get_double

$result = array_sum([1, 2, "3a", true, null, 4.5]);
if ($result != 11.5) {
    echo "FAIL: array_sum coercion expected 11.5, got {$result}\n";
    exit(1);
}

$result2 = array_sum(["abc", "0x10", "  5  ", ""]);
if ($result2 != 5) {
    echo "FAIL: array_sum edge cases expected 5, got {$result2}\n";
    exit(1);
}

echo "OK\n";
