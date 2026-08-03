--TEST--
AOT preg_match bare \d returns 1 (#27250)
--FILE--
<?php
var_export(preg_match('/\d/', 'a1'));
echo "\n";
var_export(preg_match('/\d/', 'abc'));
echo "\n";
var_export(preg_match('/\d/', 'a12b'));
echo "\n";
--EXPECT--
1
0
1
