--TEST--
stdlib generator_to_array() — not advertised on PHP 8.2 reference profile (#16723, ext/standard/array.c)
--FILE--
<?php
echo function_exists('generator_to_array') ? "fail\n" : "ok\n";
--EXPECT--
ok
