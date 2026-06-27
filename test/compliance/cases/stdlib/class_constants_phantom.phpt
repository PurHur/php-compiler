--TEST--
stdlib class_constants() — not advertised on PHP 8.2 reference profile (#12448)
--FILE--
<?php
declare(strict_types=1);

echo function_exists('class_constants') ? "fail\n" : "ok\n";
--EXPECT--
ok
