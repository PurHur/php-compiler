<?php
declare(strict_types=1);

try {
    var_export(array_splice([], 0, 0, ['x']));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}

$a = [];
var_export(array_splice($a, 0, 0, ['x']));
echo "\n";
