--TEST--
stdlib checkdate() — JIT lowering (#3292)
--JIT--
--FILE--
<?php
var_export(checkdate(2, 29, 2024));
echo "\n";
var_export(checkdate(2, 29, 2023));
echo "\n";
var_export(checkdate(13, 1, 2026));
echo "\n";
--EXPECT--
true
false
false
