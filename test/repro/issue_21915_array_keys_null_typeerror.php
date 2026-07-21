<?php

// #21915 — array_keys(null) TypeError (php-src ext/standard/array.c)
try {
    var_export(array_keys(null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
