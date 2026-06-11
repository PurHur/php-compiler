--TEST--
stdlib checkdate() — calendar validation (#3292)
--FILE--
<?php
echo function_exists('checkdate') ? '1' : '0';
echo "\n";
var_export(checkdate(2, 29, 2024));
echo "\n";
var_export(checkdate(2, 29, 2023));
echo "\n";
var_export(checkdate(13, 1, 2026));
echo "\n";
var_export(checkdate(0, 1, 2026));
echo "\n";
--EXPECT--
1
true
false
false
false
