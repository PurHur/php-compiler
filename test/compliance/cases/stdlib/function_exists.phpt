--TEST--
Stdlib: function_exists() (VM, #1216)
--FILE--
<?php
function helper() { return 1; }
echo function_exists('helper') ? '1' : '0';
echo function_exists('strlen') ? '1' : '0';
echo function_exists('missing_fn') ? '1' : '0';
echo "\n";
--EXPECT--
110
