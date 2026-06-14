--TEST--
stdlib str_split() JIT — numeric-string length coercion (#4204, ext/standard/string.c)
--FILE--
<?php
$len = '2';
var_export(str_split('hi', $len));
echo "\n";
--EXPECT--
array (
  0 => 'hi',
)
