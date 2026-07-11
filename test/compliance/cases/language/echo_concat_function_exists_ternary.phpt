--TEST--
language: echo concat prefix survives ?: with function_exists() ternary (#14133)
--FILE--
<?php
$fn = 'strlen';
echo $fn . ':' . (function_exists($fn) ? 'y' : 'n');
--EXPECT--
strlen:y
