--TEST--
stdlib http_build_query() nested inline array + 4 positional args (#12008, ext/standard/http.c)
--FILE--
<?php
echo http_build_query(['a' => ['x', 'y']], '', '&', PHP_QUERY_RFC1738), "\n";
echo http_build_query(['a' => ['x', 'y']], '', null, PHP_QUERY_RFC3986), "\n";
--EXPECT--
a%5B0%5D=x&a%5B1%5D=y
0=x&1=y
