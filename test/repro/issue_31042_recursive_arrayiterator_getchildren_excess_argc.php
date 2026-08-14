<?php

declare(strict_types=1);

$it = new RecursiveArrayIterator([1, [2, 3]]);
$it->next();
try {
    var_export($it->hasChildren(1));
    echo "\n";
} catch (Throwable $e) {
    echo 'hasChildren: ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    echo get_class($it->getChildren(1)), "\n";
} catch (Throwable $e) {
    echo 'getChildren: ', get_class($e), ': ', $e->getMessage(), "\n";
}
