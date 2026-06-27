--TEST--
stdlib HTTP last response headers — not advertised on PHP 8.2 reference profile (#12855, ext/standard/http.c)
--FILE--
<?php
declare(strict_types=1);

$bad = array_filter(
    ['http_get_last_response_headers', 'get_last_response_headers', 'http_clear_last_response_headers'],
    static fn (string $fn): bool => function_exists($fn)
);
echo [] === $bad ? "ok\n" : "fail\n";
echo function_exists('get_headers') ? "get_headers-ok\n" : "get_headers-fail\n";
--EXPECT--
ok
get_headers-ok
