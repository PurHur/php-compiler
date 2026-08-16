<?php
error_reporting(E_ALL);
try {
    new CallbackFilterIterator(new ArrayIterator([1]), null);
    echo "no_throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
