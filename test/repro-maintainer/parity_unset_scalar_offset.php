<?php

/** Issue #4880 — unset() on scalar offset must throw catchable Error (Zend/zend_execute.c). */

$x = 1;
try {
    unset($x[0]);
    echo "unset\n";
} catch (\Error $e) {
    echo $e->getMessage(), "\n";
}
