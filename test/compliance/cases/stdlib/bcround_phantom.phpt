--TEST--
stdlib bcround() — not advertised on PHP 8.2 reference profile (#16709)
--FILE--
<?php
echo function_exists('bcround') ? "fail\n" : "ok\n";
--EXPECT--
ok
