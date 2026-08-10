<?php
/**
 * Repro for #29779: random_int(null, …) / random_int(…, null) under strict_types.
 *
 * Zend: TypeError Argument #1 ($min) / #2 ($max) must be of type int, null given.
 */

declare(strict_types=1);

try {
    echo random_int(null, 10), "\n";
    echo "uncaught min\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    echo random_int(0, null), "\n";
    echo "uncaught max\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
