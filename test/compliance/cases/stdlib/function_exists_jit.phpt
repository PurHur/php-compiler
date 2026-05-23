--TEST--
stdlib function_exists() JIT with string literals (issue #1216)
--FILE--
<?php
echo function_exists('strlen') ? '1' : '0', "\n";
echo function_exists('missing_fn_xyz') ? '1' : '0', "\n";
--EXPECT--
1
0
