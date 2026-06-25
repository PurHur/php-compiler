<?php
declare(strict_types=1);

// php-src ext/spl/spl_array.c — ArrayIterator() no-arg ctor (#11792).

try {
    $it = new ArrayIterator();
    $it->append(1);
    $it->append(2);
    echo count($it) === 2 ? "ok\n" : 'fail count=' . count($it) . "\n";
} catch (Throwable $e) {
    echo 'fail ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    exit(1);
}

try {
    new ArrayIterator(null);
    echo "fail null accepted\n";
    exit(1);
} catch (TypeError $e) {
    echo str_contains($e->getMessage(), 'array') ? "null_rejected\n" : ('fail null msg=' . $e->getMessage() . "\n");
}
