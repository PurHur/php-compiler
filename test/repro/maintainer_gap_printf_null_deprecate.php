<?php

error_reporting(E_ALL);
try {
    printf(null);
    echo "printf: ok\n";
} catch (Throwable $e) {
    echo 'printf: ', $e::class, ': ', $e->getMessage(), "\n";
}
