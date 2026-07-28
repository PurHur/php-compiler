<?php
/**
 * #24273 — ParentIterator(non-RecursiveIterator) must TypeError like php-src typed ctor.
 */
error_reporting(E_ALL);
try {
    new ParentIterator(new ArrayIterator([1]));
    echo "no throw\n";
    exit(1);
} catch (TypeError $e) {
    echo 'TypeError', "\n";
    echo $e->getMessage(), "\n";
    exit(0);
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
    exit(1);
}
