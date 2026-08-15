<?php

declare(strict_types=1);

if (!function_exists('http_get_last_response_headers')) {
    echo "fail: http_get_last_response_headers missing\n";
    exit(1);
}
if (!function_exists('http_clear_last_response_headers')) {
    echo "fail: http_clear_last_response_headers missing\n";
    exit(1);
}
if (function_exists('get_last_response_headers')) {
    echo "fail: get_last_response_headers phantom present (#28412)\n";
    exit(1);
}
if (!function_exists('stream_context_set_options')) {
    echo "fail: stream_context_set_options missing\n";
    exit(1);
}
if (!function_exists('array_all')) {
    echo "fail: array_all missing (control)\n";
    exit(1);
}

echo "ok\n";
