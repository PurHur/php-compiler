--TEST--
stdlib header_list() phantom — absent on all profiles incl. 8.4 (#28404, re-#12546)
--FILE--
<?php
declare(strict_types=1);

echo function_exists('header_list') ? "list-fail\n" : "list-ok\n";
echo function_exists('headers_list') ? "headers-ok\n" : "headers-fail\n";
echo function_exists('header') ? "header-ok\n" : "header-fail\n";
--EXPECT--
list-ok
headers-ok
header-ok
