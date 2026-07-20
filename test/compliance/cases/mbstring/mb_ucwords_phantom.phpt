--TEST--
mbstring mb_ucwords() — not advertised on PHP 8.2 reference profile (#20799, ext/mbstring/mbstring.c)
--FILE--
<?php
echo function_exists('mb_ucwords') ? "fail\n" : "ok\n";
--EXPECT--
ok
