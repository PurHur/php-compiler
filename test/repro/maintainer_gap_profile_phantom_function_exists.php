<?php

declare(strict_types=1);

// Issue #16357 — PHP 8.4-only symbols must not appear in function_exists() on 8.2 reference profile.
$phantom = [
    'str_increment',
    'str_decrement',
    'zend_thread_id',
    'readonly',
];

$advertised = array_filter($phantom, static fn (string $fn): bool => function_exists($fn));
if ([] !== $advertised) {
    echo 'fail: advertised '.implode(',', $advertised)."\n";
    exit(1);
}

echo "ok\n";
