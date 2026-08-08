--TEST--
stdlib str_replace/str_ireplace coerce non-string array subject values JIT (#27165, ext/standard/string.c)
--FILE--
<?php
var_export(str_replace('1', 'X', [12, '13']));
echo "\n";
var_export(str_ireplace('a', 'X', ['a', 12, 'A']));
echo "\n";
--EXPECT--
array (
  0 => 'X2',
  1 => 'X3',
)
array (
  0 => 'X',
  1 => '12',
  2 => 'X',
)
