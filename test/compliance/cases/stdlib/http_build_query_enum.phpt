--TEST--
stdlib http_build_query() backed/unit enum case values (#5654, ext/standard/http.c)
--FILE--
<?php
enum E: int { case A = 1; }
enum U { case B; }
echo http_build_query(['k' => E::A]), "\n";
echo http_build_query(['k' => U::B]), "\n";
--EXPECT--
k%5Bname%5D=A&k%5Bvalue%5D=1
k%5Bname%5D=B
