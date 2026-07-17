<?php
declare(strict_types=1);

foreach ([
    'array_diff' => fn () => array_diff(null, [1]),
    'array_intersect' => fn () => array_intersect(null, [1]),
    'array_replace_recursive' => fn () => array_replace_recursive(null),
] as $name => $fn) {
    try {
        $fn();
        echo $name, ": uncaught\n";
    } catch (Throwable $e) {
        echo $name, ': ', $e->getMessage(), "\n";
    }
}
