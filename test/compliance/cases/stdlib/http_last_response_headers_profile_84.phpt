--TEST--
stdlib HTTP last response headers — advertised on PHP_COMPILER_PROFILE=8.4 (#16494, ext/standard/http.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

foreach (['http_get_last_response_headers', 'get_last_response_headers', 'http_clear_last_response_headers'] as $fn) {
    if (!function_exists($fn)) {
        echo "missing:$fn\n";
        exit(1);
    }
}
echo "ok\n";
echo is_array(http_get_last_response_headers()) ? "array\n" : "not-array\n";
http_clear_last_response_headers();
echo http_get_last_response_headers() === [] ? "cleared\n" : "not-cleared\n";
--EXPECT--
ok
array
cleared
