<?php

declare(strict_types=1);

$functions = [
    'get_declared_classes',
    'get_declared_interfaces',
    'get_declared_traits',
];

foreach ($functions as $fn) {
    try {
        $fn(true);
        echo "fail: {$fn}(true) accepted on reference profile\n";
        exit(1);
    } catch (\ArgumentCountError $e) {
        if (!str_contains($e->getMessage(), 'expects exactly 0 arguments')) {
            echo "fail: {$fn} wrong message: {$e->getMessage()}\n";
            exit(1);
        }
    }
}

echo "ok\n";
