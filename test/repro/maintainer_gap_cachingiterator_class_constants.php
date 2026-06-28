<?php

declare(strict_types=1);

// Repro for #13156 — CachingIterator flag constants (ext/spl/spl_iterators.c).
$expected = [
    'CALL_TOSTRING' => 1,
    'TOSTRING_USE_KEY' => 2,
    'TOSTRING_USE_CURRENT' => 4,
    'TOSTRING_USE_INNER' => 8,
    'FULL_CACHE' => 256,
];

foreach ($expected as $name => $value) {
    $const = 'CachingIterator::'.$name;
    if (!\defined($const)) {
        echo 'fail: undefined '.$const, PHP_EOL;
        exit(1);
    }
    if (constant($const) !== $value) {
        echo 'fail: '.$const.'='.constant($const).' expected '.$value, PHP_EOL;
        exit(1);
    }
}

echo 'ok', PHP_EOL;
