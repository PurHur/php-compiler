<?php

// #21914 — count(null)/sizeof(null) TypeError (php-src ext/standard/array.c)
foreach (['count', 'sizeof'] as $f) {
    try {
        var_export($f(null));
        echo "\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
