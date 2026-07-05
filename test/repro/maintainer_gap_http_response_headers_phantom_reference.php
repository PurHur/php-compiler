<?php

declare(strict_types=1);

// Issue #16346 — PHP 8.4 forward profile must not advertise HTTP header helpers on 8.4.0-dev reference.
putenv('PHP_COMPILER_PROFILE=8.4');

$bad = array_filter(
    ['http_get_last_response_headers', 'get_last_response_headers', 'http_clear_last_response_headers'],
    static fn (string $fn): bool => function_exists($fn)
);
if ([] !== $bad) {
    echo 'fail: advertised '.implode(',', $bad)."\n";
    exit(1);
}

if (!\is_array(http_get_last_response_headers())) {
    echo "fail: not callable\n";
    exit(1);
}

echo "ok\n";
