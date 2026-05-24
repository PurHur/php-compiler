--TEST--
Stdlib: enum_exists() missing and dynamic name (VM, #1373)
--FILE--
<?php
$name = 'Missing';
echo enum_exists('Missing') ? '1' : '0';
echo enum_exists($name) ? '1' : '0';
echo enum_exists('missing') ? '1' : '0';
echo function_exists('enum_exists') ? '1' : '0';
echo "\n";
--EXPECT--
0001
