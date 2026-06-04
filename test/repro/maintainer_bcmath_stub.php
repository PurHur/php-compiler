<?php

declare(strict_types=1);

var_export(function_exists('bcadd'));
echo "\n";
try {
    echo bcadd('1.234', '5', 2), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
