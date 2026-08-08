--TEST--
stdlib Sorting / SortDirection phantoms absent under all profiles (#28930, re-#12362)
--FILE--
<?php
echo enum_exists('Sorting', false) ? "fail\n" : "ok\n";
echo enum_exists('SortDirection', false) ? "fail\n" : "ok\n";
echo class_exists('Sorting', false) ? "fail\n" : "ok\n";
echo class_exists('SortDirection', false) ? "fail\n" : "ok\n";
--EXPECT--
ok
ok
ok
ok
