--TEST--
stdlib class_uses_recursive() — not advertised on PHP 8.2 reference profile (#12816)
--FILE--
<?php
echo function_exists('class_uses_recursive') ? "fail\n" : "ok\n";
--EXPECT--
ok
