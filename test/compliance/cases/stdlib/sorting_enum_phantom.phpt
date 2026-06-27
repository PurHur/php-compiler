--TEST--
stdlib Sorting / SortDirection enums — not registered on PHP 8.2 reference profile (#12362)
--FILE--
<?php
echo class_exists('Sorting', false) ? "fail\n" : "ok\n";
echo enum_exists('SortDirection', false) ? "fail\n" : "ok\n";
--EXPECT--
ok
ok
