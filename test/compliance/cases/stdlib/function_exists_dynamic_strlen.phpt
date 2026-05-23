--TEST--
stdlib function_exists() dynamic variable with compile-time string (issue #1216)
--FILE--
<?php
$key = 'strlen';
echo function_exists($key) ? "1\n" : "0\n";
--EXPECT--
1
