--TEST--
stdlib mb_split("") — empty pattern returns subject (#29496, ext/mbstring/php_mbregex.c)
--FILE--
<?php
error_reporting(E_ALL);
var_export(mb_split('', 'hello'));
echo "\n";
var_export(mb_split('', 'hello', 1));
echo "\n";
var_export(mb_split('', 'hello', 2));
echo "\n";
var_export(mb_split('', ''));
echo "\n";
var_export(mb_split('a', 'ab'));
echo "\n";
--EXPECT--
array (
  0 => 'hello',
)
array (
  0 => 'hello',
)
array (
  0 => 'hello',
)
array (
  0 => '',
)
array (
  0 => '',
  1 => 'b',
)
