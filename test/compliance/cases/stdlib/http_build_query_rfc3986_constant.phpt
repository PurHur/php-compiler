--TEST--
Stdlib: http_build_query() PHP_QUERY_RFC3986 — trailing global const arg wiring (#15932)
--FILE--
<?php
echo http_build_query(['a' => ['b' => 1, 'c' => 2]], '', '&', PHP_QUERY_RFC3986), "\n";
echo http_build_query(['a' => ['x', 'y']], '', '&', PHP_QUERY_RFC1738), "\n";
--EXPECT--
a%5Bb%5D=1&a%5Bc%5D=2
a%5B0%5D=x&a%5B1%5D=y
