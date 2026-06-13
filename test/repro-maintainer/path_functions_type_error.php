<?php

declare(strict_types=1);

$tests = [
    'basename_suffix' => function () {
        basename('/path', []);
    },
    'dirname_levels' => function () {
        dirname('/a/b/c', []);
    },
];
foreach ($tests as $label => $fn) {
    try {
        $fn();
    } catch (Throwable $e) {
        echo $label, ': ', $e::class, ': ', $e->getMessage(), "\n";
    }
}
