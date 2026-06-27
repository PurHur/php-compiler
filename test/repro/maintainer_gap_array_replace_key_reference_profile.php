<?php

declare(strict_types=1);

if (function_exists('array_replace_key')) {
    echo "fail: array_replace_key registered on reference profile\n";
    exit(1);
}

if (!function_exists('array_replace')) {
    echo "fail: array_replace missing\n";
    exit(1);
}

echo "ok\n";
