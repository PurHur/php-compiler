--TEST--
stdlib header_list() — not advertised on PHP 8.2 reference profile (#12546)
--FILE--
<?php
declare(strict_types=1);

echo function_exists('header_list') ? "list-fail\n" : "list-ok\n";
echo function_exists('header') ? "header-ok\n" : "header-fail\n";
--EXPECT--
list-ok
header-ok
