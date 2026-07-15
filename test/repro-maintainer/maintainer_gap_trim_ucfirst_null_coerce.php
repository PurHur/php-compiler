<?php

declare(strict_types=1);

$checks = [
    'trim' => static fn () => trim(null),
    'ltrim' => static fn () => ltrim(null),
    'rtrim' => static fn () => rtrim(null),
    'ucfirst' => static fn () => ucfirst(null),
    'lcfirst' => static fn () => lcfirst(null),
];

foreach ($checks as $name => $fn) {
    try {
        $result = $fn();
        echo $name, '=', var_export($result, true), "\n";
    } catch (TypeError $e) {
        echo $name, ': TypeError ', $e->getMessage(), "\n";
        exit(1);
    }
}
