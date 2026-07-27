--TEST--
Stdlib: http_build_query() backed enum → scalar under PROFILE=8.4 JIT (#23703, ext/standard/http.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
enum E: string { case A = 'a'; }
enum I: int { case One = 1; }
echo http_build_query(['e' => E::A]), "\n";
echo http_build_query(['i' => I::One]), "\n";
--EXPECT--
e=a
i=1
