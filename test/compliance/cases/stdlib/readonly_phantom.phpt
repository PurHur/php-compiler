--TEST--
stdlib readonly() — not advertised on PHP 8.2 reference profile (#12607)
--FILE--
<?php
echo function_exists('readonly') ? "fail\n" : "ok\n";
--EXPECT--
ok
