<?php
/**
 * http_* / get_*_handler excess argc → ArgumentCountError (#28683).
 * php-src: ext/standard/http.c / basic_functions.c
 */
$cases = [
    'http_get' => static fn () => http_get_last_response_headers('x'),
    'http_clear' => static fn () => http_clear_last_response_headers('x'),
    'get_error' => static fn () => get_error_handler('x'),
    'get_exception' => static fn () => get_exception_handler('x'),
    'http_get_ok' => static fn () => http_get_last_response_headers(),
    'http_clear_ok' => static fn () => http_clear_last_response_headers(),
];
foreach ($cases as $k => $f) {
    try {
        $r = $f();
        echo $k, ':OK:', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $k, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}
