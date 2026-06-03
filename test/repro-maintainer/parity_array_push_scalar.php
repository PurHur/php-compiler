<?php

/** Issue #4881 — array_push() on scalar must throw catchable Error (ext/standard/array.c). */

try {
    array_push(1, 2);
    echo "push\n";
} catch (\Error $e) {
    echo $e->getMessage(), "\n";
}
