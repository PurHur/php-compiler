--TEST--
stdlib array_replace_key() — not advertised on PHP 8.2 reference profile (#12826, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

echo function_exists('array_replace_key') ? "fail\n" : "ok\n";
echo function_exists('array_replace') ? "replace-ok\n" : "replace-fail\n";
--EXPECT--
ok
replace-ok
