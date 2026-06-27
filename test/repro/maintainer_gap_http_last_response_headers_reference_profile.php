<?php

declare(strict_types=1);

$phantom = array_filter(
    ['http_get_last_response_headers', 'get_last_response_headers', 'http_clear_last_response_headers'],
    static fn (string $fn): bool => function_exists($fn)
);

if ([] !== $phantom) {
    echo 'fail: registered on reference profile: '.implode(', ', $phantom)."\n";
    exit(1);
}

if (!function_exists('get_headers')) {
    echo "fail: get_headers missing\n";
    exit(1);
}

echo "ok\n";
