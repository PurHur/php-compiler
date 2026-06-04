<?php

/** Issue #5399 — indirect modification through string byte offset (Zend/zend_operators.c). */

$s = 'ab';
try {
    $s[0][0] = 'x';
} catch (Error $e) {
    echo 'Error: ', $e->getMessage(), "\n";
}
