--TEST--
stdlib hebrevc() — not advertised on PHP 8.2 reference profile (#17206, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
echo function_exists('hebrevc') ? "fail\n" : "ok\n";
--EXPECT--
ok
