--TEST--
Stdlib: http_build_query() enum data + bare PHP_QUERY_RFC3986 encoding_type (#23702)
--FILE--
<?php
enum E: string { case A = 'a'; }
echo http_build_query(['e' => E::A], '', '&', PHP_QUERY_RFC3986), "\n";
echo http_build_query(['e' => E::A], '', '&', PHP_QUERY_RFC1738), "\n";
echo http_build_query(['e' => E::A], encoding_type: PHP_QUERY_RFC3986), "\n";
--EXPECT--
e%5Bname%5D=A&e%5Bvalue%5D=a
e%5Bname%5D=A&e%5Bvalue%5D=a
e%5Bname%5D=A&e%5Bvalue%5D=a
