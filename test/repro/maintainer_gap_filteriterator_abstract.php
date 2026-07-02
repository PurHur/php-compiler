<?php
declare(strict_types=1);

try {
    new FilterIterator(new ArrayIterator([]));
    echo "fail: instantiated\n";
    exit(1);
} catch (Error $e) {
    echo $e->getMessage(), "\n";
    exit(0);
}
