<?php

declare(strict_types=1);

try {
    array_replace(array: [1], arrays: [2]);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    array_merge(array: [1], arrays: [2]);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

var_export(array_replace([1], [2]));
echo "\n";
