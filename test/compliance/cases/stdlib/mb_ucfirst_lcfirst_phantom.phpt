--TEST--
stdlib mb_ucfirst()/mb_lcfirst() — not advertised on PHP 8.2 reference profile (#17609, ext/mbstring/mbstring.c)
--FILE--
<?php
echo function_exists('mb_ucfirst') ? "fail\n" : "ok\n";
echo function_exists('mb_lcfirst') ? "fail\n" : "ok\n";
--EXPECT--
ok
ok
