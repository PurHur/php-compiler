<?php

declare(strict_types=1);

if (!function_exists('bcround')) {
    echo "fail: function_exists(bcround) false under PHP_COMPILER_PROFILE=8.4\n";
    exit(1);
}

echo bcround('1.234', 2), "\n";
echo "ok\n";
